<?php

declare(strict_types=1);

namespace db;

use Tarantool\Client\Client;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Queue\Queue;

class Manager
{
    private const SQL_CRT_PST = 'INSERT INTO posts VALUES (null, ?, ?, 0, ?)';
    private const SQL_USR_PSTS = 'SELECT * FROM posts WHERE "creator_id" = ? AND "removed"=0 ORDER BY "id" DESC LIMIT 50';
    private const SQL_USR_PSTS_PAGE = 'SELECT * FROM posts WHERE "creator_id" = ? AND "id" < ? AND "removed"=0 ORDER BY "id" DESC LIMIT 50';
    private const SQL_PST = 'SELECT * FROM posts WHERE "id" = ? AND "removed"=0 LIMIT 1';
    private const SQL_USR = 'SELECT * FROM users WHERE "id" = ? LIMIT 1';
    private const SQL_UPD_PST = 'UPDATE posts SET "text"=? WHERE "id"=? AND "creator_id"=? AND "removed"=0';
    private const SQL_SUBSCRIBE = 'INSERT INTO followers VALUES (null, ?, ?)';
    private const SQL_UNSUBSCRIBE = 'DELETE FROM followers WHERE "src_id"=? AND "dst_id"=?';
    private const SQL_DEL_PST = 'UPDATE posts SET "removed"=1 WHERE "id" = ? AND "creator_id"=? AND "removed"=0';

    private const MQ_TOPIC_NEW_POST = 'new_post';

    private $dbClient;
    private $queueNewMsg;

    function __construct(Client $dbClient)
    {
        $this->dbClient = $dbClient;
        $this->queueNewMsg = new Queue($dbClient, $this::MQ_TOPIC_NEW_POST);
    }

    public function getUser(int $userID) : ?User
    {
        if ($userID === 0) {
            return null;
        }
        $result = $this->dbClient->executeQuery($this::SQL_USR, $userID);
        if ($result->count() === 0) {
            return null;
        }
        return new User($result->getData()[0]);
    }

    public function createPost(int $creatorID, string $text) : int
    {
        if ($creatorID === 0) {
            return 0;
        }
        if (empty($text)) {
            return 0;
        }
        // TODO поместить размещение идентификатора поста в очередь на доставку поста
        // в ленты подписчиков в транзакцию БД.
        $result = $this->dbClient->executeUpdate($this::SQL_CRT_PST, $creatorID, time(), $text);
        $postID = $result->getAutoincrementIds()[0];
        $this->queueNewMsg->put($postID);
        return $postID;
    }

    public function getPost(int $postID) : ?Post
    {
        if ($postID === 0) {
            return null;
        }
        $result = $this->dbClient->executeQuery($this::SQL_PST, $postID);
        if ($result->count() === 0) {
            return null;
        }
        return new Post($result->getData()[0]);
    }

    public function getUserPosts(int $userID, int $lastPostID) : array
    {
        $query = $this::SQL_USR_PSTS;
        $params = [$userID];
        if ($lastPostID > 0) {
            $query = $this::SQL_USR_PSTS_PAGE;
            array_push($params, $lastPostID);
        }
        $result = $this->dbClient->executeQuery($query, ...$params);

        $posts = [];
        foreach ($result->getData() as &$row) {
            array_push($posts, new Post($row));
        }
        return $posts;
    }

    public function updatePost(int $creatorID, int $postID, ?string $txt) : bool
    {
        if (empty($creatorID) || empty($postID) || empty($txt)) {
            return false;
        }
        $result = $this->dbClient->executeUpdate($this::SQL_UPD_PST, $txt, $postID, $creatorID);
        return $result->count() > 0;
    }

    public function subscribe(int $userID, int $targetID) : bool
    {
        // TODO добавить возможность добавления 10 последних постов пользователя,
        // на которого произошла подписка, в ленту постов интересных авторов
        // инициатора подписки.
        if (empty($userID) || empty($targetID)) {
            return false;
        }
        if ($userID === $targetID) {
            return false;
        }
        $targetUser = $this->getUser($targetID);
        if (empty($targetUser)) {
            return false;
        }
        try {
            // Клиент генерирует исключение, когда пытается создаться дубликат подписки.
            // Failed to execute SQL statement: Duplicate key exists in unique index '...' in space 'FOLLOWERS'.
            $this->dbClient->executeUpdate($this::SQL_SUBSCRIBE, $userID, $targetID);
        } catch (\Exception $e) {
            return true;
        }
        return true;
    }

    public function unsubscribe(int $userID, int $targetID) : bool
    {
        // TODO добавить возможность удаления постов пользователя, от которого произошла
        // отписка, из ленты постов интересных авторов инициатора отписки.
        if (empty($userID) || empty($targetID)) {
            return false;
        }
        if ($userID === $targetID) {
            return false;
        }
        $targetUser = $this->getUser($targetID);
        if (empty($targetUser)) {
            return false;
        }
        $this->dbClient->executeUpdate($this::SQL_UNSUBSCRIBE, $userID, $targetID);
        return true;
    }

    public function deletePost(int $creatorID, int $postID) : bool
    {
        if (empty($creatorID) || empty($postID)) {
            return false;
        }
        $result = $this->dbClient->executeUpdate($this::SQL_DEL_PST, $postID, $creatorID);
        return $result->count() > 0;
    }

    public function getUserFeed(int $userID, int $lastPostID) : array
    {
        if (empty($userID)) {
            return [];
        }

        // Получить идентификаторы постов интересных авторов из ленты пользователя.
        $space = $this->dbClient->getSpace('user_feeds');
        $result = $space->select(Criteria::key([$userID]));
        $postsIDs = $result[0][1];
        if (sizeOf($postsIDs) === 0) {
            return [];
        }

        // Создать SQL запрос на получение постов.
        $queryPart = substr(json_encode($postsIDs), 1, -1);
        if ($lastPostID === 0) {
            // Если необходимо получить только первый чанк.
            $query = 'SELECT * FROM posts WHERE "id" IN ('
                . $queryPart
                . ') AND "removed"=0 ORDER BY "id" DESC LIMIT 50';
        } else {
            // Если необходимо получить не первый чанк.
            $query = 'SELECT * FROM posts WHERE "id" IN ('
                . $queryPart
                . ') AND "removed"=0 AND "id" < '
                . $lastPostID
                . ' ORDER BY "id" DESC LIMIT 50';
        }

        // Получить посты по их идентификаторам.
        $result = $this->dbClient->executeQuery($query);
        $posts = [];
        foreach ($result->getData() as &$row) {
            array_push($posts, new Post($row));
        }

        // TODO необходимо проверить имперически, важно ли моментально удалять посты
        // из ленты постов интересных авторов тех авторов, от которых отписался пользователь.
        // Если есть необходимость, то необходимо отфлитровать те посты,
        // на авторов которых не подписан данный пользователь.

        return $posts;
    }
}
