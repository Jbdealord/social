<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Service;


use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use Exception;
use OCA\Social\Db\NotesRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\NoteNotFoundException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\RequestContentException;
use OCA\Social\Exceptions\RequestNetworkException;
use OCA\Social\Exceptions\RequestResultNotJsonException;
use OCA\Social\Exceptions\RequestResultSizeException;
use OCA\Social\Exceptions\RequestServerException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;

class NoteService {


	/** @var NotesRequest */
	private $notesRequest;

	/** @var ActivityService */
	private $activityService;

	/** @var AccountService */
	private $accountService;

	/** @var SignatureService */
	private $signatureService;

	/** @var StreamQueueService */
	private $streamQueueService;

	/** @var CacheActorService */
	private $cacheActorService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/** @var Person */
	private $viewer = null;


	/**
	 * NoteService constructor.
	 *
	 * @param NotesRequest $notesRequest
	 * @param ActivityService $activityService
	 * @param AccountService $accountService
	 * @param SignatureService $signatureService
	 * @param StreamQueueService $streamQueueService
	 * @param CacheActorService $cacheActorService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		NotesRequest $notesRequest, ActivityService $activityService,
		AccountService $accountService, SignatureService $signatureService,
		StreamQueueService $streamQueueService, CacheActorService $cacheActorService,
		ConfigService $configService, MiscService $miscService
	) {
		$this->notesRequest = $notesRequest;
		$this->activityService = $activityService;
		$this->accountService = $accountService;
		$this->signatureService = $signatureService;
		$this->streamQueueService = $streamQueueService;
		$this->cacheActorService = $cacheActorService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Person $viewer
	 */
	public function setViewer(Person $viewer) {
		$this->viewer = $viewer;
		$this->notesRequest->setViewer($viewer);
	}


	/**
	 * @param Person $actor
	 * @param string $postId
	 * @param ACore|null $announce
	 *
	 * @return string
	 * @throws NoteNotFoundException
	 * @throws SocialAppConfigException
	 * @throws Exception
	 */
	public function createBoost(Person $actor, string $postId, ACore &$announce = null): string {

		$announce = new Announce();
		$this->assignStream($announce, $actor, Stream::TYPE_PUBLIC);
		$announce->setActor($actor);

		$note = $this->getNoteById($postId, true);
		if ($note->getType() !== Note::TYPE) {
			throw new NoteNotFoundException('Stream is not a Note');
		}

		$announce->addCc($note->getAttributedTo());
		if ($note->isLocal()) {
			$announce->setObject($note);
		} else {
			$announce->setObjectId($note->getId());
			$announce->addCacheItem($note->getId());
		}

		$this->signatureService->signObject($actor, $announce);
		$token = $this->activityService->request($announce);

		$this->streamQueueService->cacheStreamByToken($token);

		return $token;
	}


	/**
	 * @param Stream $stream
	 * @param Person $actor
	 * @param string $type
	 *
	 * @throws SocialAppConfigException
	 */
	public function assignStream(Stream &$stream, Person $actor, string $type) {
		$stream->setId($this->configService->generateId('@' . $actor->getPreferredUsername()));
		$stream->setPublished(date("c"));

		$this->setRecipient($stream, $actor, $type);
		$stream->convertPublished();
		$stream->setLocal(true);
	}


	/**
	 * @param Stream $stream
	 * @param Person $actor
	 * @param string $type
	 */
	private function setRecipient(Stream $stream, Person $actor, string $type) {
		switch ($type) {
			case Note::TYPE_UNLISTED:
				$stream->setTo($actor->getFollowers());
				$stream->addInstancePath(
					new InstancePath(
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				$stream->addCc(ACore::CONTEXT_PUBLIC);
				break;

			case Note::TYPE_FOLLOWERS:
				$stream->setTo($actor->getFollowers());
				$stream->addInstancePath(
					new InstancePath(
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				break;

			case Note::TYPE_DIRECT:
				break;

			default:
				$stream->setTo(ACore::CONTEXT_PUBLIC);
				$stream->addCc($actor->getFollowers());
				$stream->addInstancePath(
					new InstancePath(
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				break;
		}
	}


	/**
	 * @param Stream $stream
	 * @param string $type
	 * @param string $account
	 */
	public function addRecipient(Stream $stream, string $type, string $account) {
		if ($account === '') {
			return;
		}

		try {
			$actor = $this->cacheActorService->getFromAccount($account);
		} catch (Exception $e) {
			return;
		}

		$instancePath = new InstancePath(
			$actor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM
		);
		if ($type === Note::TYPE_DIRECT) {
			$instancePath->setPriority(InstancePath::PRIORITY_HIGH);
			$stream->addToArray($actor->getId());
		} else {
			$stream->addCc($actor->getId());
		}

		$stream->addTag(
			[
				'type' => 'Mention',
				'href' => $actor->getId(),
				'name' => '@' . $account
			]
		);

		$stream->addInstancePath($instancePath);
	}


	/**
	 * @param Note $note
	 * @param string $hashtag
	 */
	public function addHashtag(Note $note, string $hashtag) {
		try {
			$note->addTag(
				[
					'type' => 'Hashtag',
					'href' => $this->configService->getCloudAddress() . '/tag/' . strtolower(
							$hashtag
						),
					'name' => '#' . $hashtag
				]
			);
		} catch (SocialAppConfigException $e) {
		}
	}


	/**
	 * @param Stream $stream
	 * @param string $type
	 * @param array $accounts
	 */
	public function addRecipients(Stream $stream, string $type, array $accounts) {
		foreach ($accounts as $account) {
			$this->addRecipient($stream, $type, $account);
		}
	}


	/**
	 * @param Note $note
	 * @param array $hashtags
	 */
	public function addHashtags(Note $note, array $hashtags) {
		$note->setHashtags($hashtags);
		foreach ($hashtags as $hashtag) {
			$this->addHashtag($note, $hashtag);
		}
	}


	/**
	 * @param Note $note
	 * @param string $replyTo
	 *
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws NoteNotFoundException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestResultNotJsonException
	 */
	public function replyTo(Note $note, string $replyTo) {
		if ($replyTo === '') {
			return;
		}

		$author = $this->getAuthorFromPostId($replyTo);
		$note->setInReplyTo($replyTo);
		// TODO - type can be NOT public !
		$note->addInstancePath(
			new InstancePath(
				$author->getSharedInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH
			)
		);
	}


	/**
	 * @param Note $note
	 *
	 * @throws Exception
	 */
	public function deleteLocalNote(Note $note) {
		if (!$note->isLocal()) {
			return;
		}

		$note->setActorId($note->getAttributedTo());
		$this->activityService->deleteActivity($note);
		$this->notesRequest->deleteNoteById($note->getId());
	}


	/**
	 * @param string $id
	 * @param bool $asViewer
	 *
	 * @return Note
	 * @throws NoteNotFoundException
	 */
	public function getNoteById(string $id, bool $asViewer = false): Note {
		return $this->notesRequest->getNoteById($id, $asViewer);
	}


	/**
	 * @param Person $actor
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamHome(Person $actor, int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamHome($actor, $since, $limit);
	}


	/**
	 * @param Person $actor
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamNotifications(Person $actor, int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamNotifications($actor, $since, $limit);
	}


	/**
	 * @param string $actorId
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamAccount(string $actorId, int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamAccount($actorId, $since, $limit);
	}


	/**
	 * @param Person $actor
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamDirect(Person $actor, int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamDirect($actor, $since, $limit);
	}


	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamLocalTimeline(int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamTimeline($since, $limit, true);
	}


	/**
	 * @param Person $actor
	 * @param string $hashtag
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamLocalTag(Person $actor, string $hashtag, int $since = 0, int $limit = 5
	): array {
		return $this->notesRequest->getStreamTag($actor, $hashtag, $since, $limit);
	}


	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamInternalTimeline(int $since = 0, int $limit = 5): array {
		// TODO - admin should be able to provide a list of 'friendly/internal' instance of ActivityPub
		return [];
	}


	/**m
	 *
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamGlobalTimeline(int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamTimeline($since, $limit, false);
	}


	/**
	 * @param $noteId
	 *
	 * @return Person
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws NoteNotFoundException
	 * @throws RedundancyLimitException
	 * @throws SocialAppConfigException
	 * @throws ItemUnknownException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws RequestResultNotJsonException
	 */
	public function getAuthorFromPostId($noteId) {
		$note = $this->notesRequest->getNoteById($noteId);

		return $this->cacheActorService->getFromId($note->getAttributedTo());
	}


}

