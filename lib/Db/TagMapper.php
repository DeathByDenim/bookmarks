<?php
namespace OCA\Bookmarks\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;

class TagMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'bookmarks_tags');
	}

	public function findAllWithCount(int $userId) {
		$qb = $this->db->getQueryBuilder();
		$qb
			->select('t.tag')
			->selectAlias($qb->createFunction('COUNT(' . $qb->getColumnName('t.bookmark_id') . ')'), 'nbr')
			->from('bookmarks_tags', 't')
			->innerJoin('t', 'bookmarks', 'b', $qb->expr()->eq('b.id', 't.bookmark_id'))
			->where($qb->expr()->eq('b.user_id', $qb->createNamedParameter($userId)));
		$qb
			->groupBy('t.tag')
			->orderBy('nbr', 'DESC');

		$tags = $qb->execute()->fetchAll();
		return $tags;
	}

	public function findAll(int $userId) {
		$qb = $this->db->getQueryBuilder();
		$qb
			->select('t.tag')
			->from('bookmarks_tags', 't')
			->innerJoin('t', 'bookmarks', 'b', $qb->expr()->eq('b.id', 't.bookmark_id'))
			->where($qb->expr()->eq('b.user_id', $qb->createNamedParameter($userId)))
			->groupBy('t.tag');
		$tags = $qb->execute()->fetchAll(\PDO::FETCH_COLUMN);
		return $tags;
	}

	public function findByBookmark(int $bookmarkId) {
		$qb = $this->db->getQueryBuilder();
		$qb->select('tag');

		$qb
			->from('bookmarks_tags', 't')
			->where($qb->expr()->eq('t.bookmark_id', $qb->createPositionalParamter($bookmarkId)));

		return $qb->fetchAll(\PDO::FETCH_COLUMN);
	}

	public function delete(int $userId, string $tag) {
		$qb = $this->db->getQueryBuilder();
		$qb
			->delete('bookmarks_tags', 'tgs')
			->innerJoin('tgs', 'bookmarks', 'bm', $qb->expr()->eq('tgs.bookmark_id', 'bm.id'))
			->where($qb->expr()->eq('tgs.tag', $qb->createNamedParameter($tag)))
			->andWhere($qb->expr()->eq('bm.user_id', $qb->createNamedParameter($userId)));
			return $qb->execute();
	}

	public function deleteAll(int $userId) {
		$qb = $this->db->getQueryBuilder();
		$qb
			->delete('bookmarks_tags', 'tgs')
			->innerJoin('tgs', 'bookmarks', 'bm', $qb->expr()->eq('tgs.bookmark_id', 'bm.id'))
			->where($qb->expr()->eq('bm.user_id', $qb->createNamedParameter($userId)));
			return $qb->execute();
	}

	public function addTo($tags, int $bookmarkId) {
		if (is_string($tags)) {
			$tags = [$tags];
		}else if (!is_array($tags)) {
			throw new InvalidArgumentException('$tag must be string or array of strings');
		}
		foreach($tags as $tag) {
			$tag = trim($tag);
			if (empty($tag)) {
				//avoid saving white spaces
				continue;
			}

			// check if tag for this bookmark exists
			$qb = $this->db->getQueryBuilder();
			$qb
				->select('*')
				->from('bookmarks_tags')
				->where($qb->expr()->eq('bookmark_id', $qb->createNamedParameter($bookmarkId)))
				->andWhere($qb->expr()->eq('tag', $qb->createNamedParameter($tag)));
			if ($qb->execute()->fetch()) {
				continue;
			}

			$qb = $this->db->getQueryBuilder();
			$qb
				->insert('bookmarks_tags')
				->values([
					'tag' => $qb->createNamedParameter($tag),
					'bookmark_id' => $qb->createNamedParameter($bookmarkId)
				]);
			$qb->execute();
		}
	}

	public function removeAllFrom(int $bookmarkId) {
		// Remove old tags
		$qb = $this->db->getQueryBuilder();
		$qb
			->delete('bookmarks_tags')
			->where($qb->expr()->eq('bookmark_id', $qb->createNamedParameter($bookmarkId)));
		$qb->execute();
	}

	public function setOn(array $tags, int $bookmarkId) {
		$this->removeAllFrom($bookmarkId);
		$this->addTo($tags, $bookmarkId);
	}

	/**
	 * @brief Rename a tag
	 * @param string $userId UserId
	 * @param string $old Old Tag Name
	 * @param string $new New Tag Name
	 * @return boolean Success of operation
	 */
	public function renameTag(int $userId, string $old, string $new) {
		// Remove about-to-be duplicated tags
		$qb = $this->db->getQueryBuilder();
		$qb
			->select('tgs.bookmark_id')
			->from('bookmarks_tags', 'tgs')
			->innerJoin('tgs', 'bookmarks', 'bm', $qb->expr()->eq('tgs.bookmark_id', 'bm.id'))
			->innerJoin('tgs', 'bookmarks_tags', 't', $qb->expr()->eq('tgs.bookmark_id', 't.bookmark_id'))
			->where($qb->expr()->eq('tgs.tag', $qb->createNamedParameter($new)))
			->andWhere($qb->expr()->eq('bm.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('t.tag', $qb->createNamedParameter($old)));
		$duplicates = $qb->execute()->fetchAll(\PDO::FETCH_COLUMN);
		if (count($duplicates) !== 0) {
			$qb = $this->db->getQueryBuilder();
			$qb
				->delete('bookmarks_tags')
				->where($qb->expr()->in('bookmark_id', array_map([$qb, 'createNamedParameter'], $duplicates)))
				->andWhere($qb->expr()->eq('tag', $qb->createNamedParameter($old)));
			$qb->execute();
		}

		// Update tags to the new label
		$qb = $this->db->getQueryBuilder();
		$qb
			->select('tgs.bookmark_id')
			->from('bookmarks_tags', 'tgs')
			->innerJoin('tgs', 'bookmarks', 'bm', $qb->expr()->eq('tgs.bookmark_id', 'bm.id'))
			->where($qb->expr()->eq('tgs.tag', $qb->createNamedParameter($old)))
			->andWhere($qb->expr()->eq('bm.user_id', $qb->createNamedParameter($userId)));
		$bookmarks = $qb->execute()->fetchAll(\PDO::FETCH_COLUMN);
		if (count($bookmarks) !== 0) {
			$qb = $this->db->getQueryBuilder();
			$qb
				->update('bookmarks_tags')
				->set('tag', $qb->createNamedParameter($new))
				->where($qb->expr()->eq('tag', $qb->createNamedParameter($old)))
				->andWhere($qb->expr()->in('bookmark_id', array_map([$qb, 'createNamedParameter'], $bookmarks)));
			$qb->execute();
		}
		return true;
	}
}