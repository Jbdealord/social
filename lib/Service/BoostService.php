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


use daita\MySmallPhpTools\Traits\TStringTools;
use Exception;
use OCA\Social\AP;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;


/**
 * Class BoostService
 *
 * @package OCA\Social\Service
 */
class BoostService {


	use TStringTools;


	/** @var StreamRequest */
	private $streamRequest;

	/** @var NoteService */
	private $noteService;

	/** @var SignatureService */
	private $signatureService;

	/** @var ActivityService */
	private $activityService;

	/** @var StreamActionService */
	private $streamActionService;

	/** @var StreamQueueService */
	private $streamQueueService;

	/** @var MiscService */
	private $miscService;


	/**
	 * BoostService constructor.
	 *
	 * @param StreamRequest $streamRequest
	 * @param NoteService $noteService
	 * @param SignatureService $signatureService
	 * @param ActivityService $activityService
	 * @param StreamActionService $streamActionService
	 * @param StreamQueueService $streamQueueService
	 * @param MiscService $miscService
	 */
	public function __construct(
		StreamRequest $streamRequest, NoteService $noteService, SignatureService $signatureService,
		ActivityService $activityService, StreamActionService $streamActionService,
		StreamQueueService $streamQueueService, MiscService $miscService
	) {
		$this->streamRequest = $streamRequest;
		$this->noteService = $noteService;
		$this->signatureService = $signatureService;
		$this->activityService = $activityService;
		$this->streamActionService = $streamActionService;
		$this->streamQueueService = $streamQueueService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Person $actor
	 * @param string $postId
	 * @param string $token
	 *
	 * @return ACore
	 * @throws StreamNotFoundException
	 * @throws SocialAppConfigException
	 * @throws Exception
	 */
	public function create(Person $actor, string $postId, &$token = ''): ACore {

		try {
			return $this->get($actor, $postId);
		} catch (StreamNotFoundException $e) {
		}

		$announce = AP::$activityPub->getItemFromType(Announce::TYPE);
		$this->noteService->assignItem($announce, $actor, Stream::TYPE_PUBLIC);
		$announce->setActor($actor);

		$note = $this->noteService->getNoteById($postId, true);
		if ($note->getType() !== Note::TYPE) {
			throw new StreamNotFoundException('Stream is not a Note');
		}

		$announce->addCc($note->getAttributedTo());
		$announce->setObjectId($note->getId());
		$announce->setRequestToken($this->uuid());

		$interface = AP::$activityPub->getInterfaceFromType(Announce::TYPE);
		$interface->save($announce);

		$this->streamActionService->setActionBool($actor->getId(), $postId, 'boosted', true);
		$this->signatureService->signObject($actor, $announce);

		$token = $this->activityService->request($announce);

		$this->streamQueueService->cacheStreamByToken($announce->getRequestToken());

		return $announce;
	}


	/**
	 * @param Person $actor
	 * @param string $postId
	 *
	 * @return Stream
	 * @throws StreamNotFoundException
	 */
	public function get(Person $actor, string $postId): Stream {
		$stream = $this->streamRequest->getStreamByObjectId($actor, Announce::TYPE, $postId);

		return $stream;
	}


	/**
	 * @param Person $actor
	 * @param string $postId
	 * @param string $token
	 *
	 * @return ACore
	 * @throws StreamNotFoundException
	 * @throws SocialAppConfigException
	 */
	public function delete(Person $actor, string $postId, &$token = ''): ACore {
		$undo = new Undo();
		$this->noteService->assignItem($undo, $actor, Stream::TYPE_PUBLIC);
		$undo->setActor($actor);

		$note = $this->noteService->getNoteById($postId, true);
		if ($note->getType() !== Note::TYPE) {
			throw new StreamNotFoundException('Stream is not a Note');
		}

		$announce = $this->streamRequest->getStreamByObjectId($actor, Announce::TYPE, $postId);

		$undo->setObject($announce);
		$undo->setCcArray($announce->getCcArray());

		$this->streamRequest->deleteStreamById($announce->getId(), Announce::TYPE);
		$this->streamActionService->setActionBool($actor->getId(), $postId, 'boosted', false);
		$this->signatureService->signObject($actor, $undo);

		$token = $this->activityService->request($undo);

		return $undo;
	}

}

