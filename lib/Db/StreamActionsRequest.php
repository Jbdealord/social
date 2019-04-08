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


namespace OCA\Social\Db;


use OCA\Social\Exceptions\StreamActionDoesNotExistException;
use OCA\Social\Model\StreamAction;


/**
 * Class StreamActionsRequest
 *
 * @package OCA\Social\Db
 */
class StreamActionsRequest extends StreamActionsRequestBuilder {


	/**
	 * create a new Queue in the database.
	 *
	 * @param StreamAction $action
	 */
	public function create(StreamAction $action) {
		$qb = $this->getStreamActionInsertSql();
		$qb->setValue('actor_id', $qb->createNamedParameter($action->getActorId()))
		   ->setValue('stream_id', $qb->createNamedParameter($action->getStreamId()))
		   ->setValue(
			   'values', $qb->createNamedParameter(
			   json_encode($action->getValues(), JSON_UNESCAPED_SLASHES)
		   )
		   );

		$qb->execute();
	}


	/**
	 * create a new Queue in the database.
	 *
	 * @param StreamAction $action
	 */
	public function update(StreamAction $action) {
		$qb = $this->getStreamActionUpdateSql();

		$values = json_encode($action->getValues(), JSON_UNESCAPED_SLASHES);
		$qb->set('values', $qb->createNamedParameter($values));

		$this->limitToActorId($qb, $action->getActorId());
		$this->limitToStreamId($qb, $action->getStreamId());

		$qb->execute();
	}


	/**
	 * @param string $actorId
	 * @param string $streamId
	 *
	 * @return StreamAction
	 * @throws StreamActionDoesNotExistException
	 */
	public function getAction(string $actorId, string $streamId): StreamAction {
		$qb = $this->getStreamActionDeleteSql();
		$this->limitToActorId($qb, $actorId);
		$this->limitToStreamId($qb, $streamId);

		$cursor = $qb->execute();
		$data = $cursor->fetch();
		if ($data === false) {
			throw new StreamActionDoesNotExistException();
		}
		$cursor->closeCursor();

		return $this->parseStreamActionsSelectSql($data);
	}


	/**
	 * @param StreamAction $action
	 */
	public function delete(StreamAction $action) {
		$qb = $this->getStreamActionDeleteSql();
		$this->limitToActorId($qb, $action->getActorId());
		$this->limitToStreamId($qb, $action->getStreamId());

		$qb->execute();
	}

}

