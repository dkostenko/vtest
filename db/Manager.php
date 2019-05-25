<?php

declare(strict_types=1);

namespace db;

use Tarantool\Client\Client;

class Manager
{
    private const SQL_CRT_PST = 'INSERT INTO posts VALUES (null, ?, ?, ?)';
    private const SQL_USR_PSTS = 'SELECT * FROM posts WHERE "creator_id" = ? ORDER BY "id" DESC LIMIT 50';
    private const SQL_USR_PSTS_PAGE = 'SELECT * FROM posts WHERE "creator_id" = ? AND "id" < ? ORDER BY "id" DESC LIMIT 50';
    private const SQL_PST = 'SELECT * FROM posts WHERE "id" = ? LIMIT 1';
    private const SQL_USR = 'SELECT * FROM users WHERE "id" = ? LIMIT 1';

    private $dbClient;

    function __construct(Client $dbClient)
    {
        $this->dbClient = $dbClient;
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
        $result = $this->dbClient->executeUpdate($this::SQL_CRT_PST, $creatorID, time(), $text);
        return $result->getAutoincrementIds()[0];
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
}
