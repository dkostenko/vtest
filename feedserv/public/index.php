<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Tarantool\Client\Client;
use Tarantool\Queue\Queue;
use Tarantool\Queue\Task;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;

const SQL_PST = 'SELECT * FROM posts WHERE "id" = ? AND "removed"=0 LIMIT 1';
const SQL_SUBS = 'SELECT * FROM followers WHERE "dst_id" = ?';

const MQ_TOPIC_NEW_POST = 'new_post';

class Post
{
    public $id;
    public $creator_id;

    function __construct($row)
    {
        $this->id = $row[0];
        $this->creator_id = $row[1];
    }
}

// Ожидает новые сообщения из очереди сообщений.
function listenQueue($dbClient, $topic) : void
{
    $queue = new Queue($dbClient, $topic);
    while (true) {
        try {
            $task = $queue->take();
        } catch (\Exception $e) {
            // TODO отправить информацию об исключении в багтрекер.
            continue;
        }

        if (empty($task)) {
            continue;
        }

        try {
            handleQueueTask($queue, $task);
        } catch (\Exception $e) {
            // TODO отправить информацию об исключении в багтрекер.
            continue;
        }
    }
}

// Обрабаывает новое сообщение из очереди сообщений.
function handleQueueTask(Queue $queue, Task $task)
{
    $postID = $task->getData();
    $post = getPost($dbClient, $postID);
    if (empty($post)) {
        ackQueueTask($queue, $task);
        return;
    }

    $subscribers = getSubscribers($dbClient, $post->creator_id);
    if (sizeof($subscribers) === 0) {
        ackQueueTask($queue, $task);
        return;
    }

    foreach($subscribers as $userID) {
        $postIDs = getUserFeed($dbClient, $userID);
        $postIDs = addPostToFeed($postIDs, $post->id);
        saveFeed($dbClient, $userID, $postIDs);
    }
    ackQueueTask($queue, $task);
}

// Убирает сообщение из очереди, чтобы оно не пришло для повторной обработки.
function ackQueueTask(Queue $queue, Task $task)
{
    $queue->ack($task->getId());
}

// Возвращает пост из БД.
function getPost(Client $dbClient, int $postID) : ?Post
{
    if ($postID === 0) {
        return null;
    }
    $result = $dbClient->executeQuery(SQL_PST, $postID);
    if ($result->count() === 0) {
        return null;
    }
    return new Post($result->getData()[0]);
}

// Возвращает идентификаторы подписчиков указанного пользователя.
function getSubscribers(Client $dbClient, int $userID) : array
{
    $result = $dbClient->executeQuery(SQL_SUBS, $userID);
    $subscribers = [];
    foreach ($result->getData() as &$row) {
        array_push($subscribers, $row[2]);
    }
    return $subscribers;
}

// Возвращает идентификаторы постов всей ленты постов интересных авторов указанного
// пользователя.
function getUserFeed(Client $dbClient, int $userID) : array
{
    if (empty($userID)) {
        return [];
    }
    // Получить идентификаторы постов интересных авторов из ленты пользователя.
    $space = $dbClient->getSpace('user_feeds');
    $result = $space->select(Criteria::key([$userID]));
    $postsIDs = $result[0][1];
    if (sizeOf($postsIDs) === 0) {
        return [];
    }
    return $postsIDs;
}

// Добавляет пост в начало ленты постов интересных авторов.
// Если длина ленты после добавления поста более определенного значения, то
// необходимо отбросить пост, который был добавлен раньше всех.
function addPostToFeed(array $feed, int $postID) : array
{
    // TODO проверить наличие поста в ленте. Пост уже может быть в ленте, т.к.
    // очередь сообщений предоставляет гарантию доставки "хотя бы 1 раз", поэтому
    // один и тот же пост может быть обработан несколько раз. Но некорректно один и
    // тот же пост добавлять в ленту 2 раза.
    array_unshift($feed, $postID);
    return array_slice($feed, 0, 1000);
}

// Сохраняет ленту постов интересных авторов определенного пользователя.
function saveFeed(Client $dbClient, int $userID, array $feed) : void
{
    $space = $dbClient->getSpace('user_feeds');
    $result = $space->upsert([$userID, $feed], Operations::set(1, $userID));
}

function main() : void
{
    $dbClient = Client::fromOptions([
        'uri' => 'tcp://192.168.99.100:3301',
        'username' => 'guest',
        'password' => '',
    ]);
    listenQueue($dbClient, MQ_TOPIC_NEW_POST);
}

main();
