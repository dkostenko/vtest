<?php

declare(strict_types=1);

namespace api;

use \db\User;

class Manager
{
    private $dbm;
    private $routeHandlers;

    function __construct($dbm)
    {
        $this->dbm = $dbm;
        $this->routeHandlers = [
            '/api/get_my_feed' => array($this, 'handlerWIP'),
            '/api/create_post' => array($this, 'handlerCreatePost'),
            '/api/get_user_posts' => array($this, 'handlerGetUserPosts'),
            '/api/get_post' => array($this, 'handlerGetPost'),
            '/api/subscribe' => array($this, 'handlerWIP'),
            '/api/unsubscribe' => array($this, 'handlerWIP'),
            '/api/update_post' => array($this, 'handlerWIP'),
            '/api/delete_post' => array($this, 'handlerWIP'),
        ];
    }

    public function handle(string $path, string $httpMethod) : void
    {
        // Установить обработчик исключений.
        set_exception_handler(function ($e) {
            # TODO отправить информацию в багтрекер, вместо print.
            print $e;

            $this->send(['data' => 'unexpected error', 'code' => 500]);
        });


        // Проверить HTTP-метод запроса.
        if ($httpMethod !== 'POST') {
            $this->send(['data' => 'unknown http method', 'code' => 400]);
            return;
        }

        // Проверить корректность заголовка Content-Type.
        $headers = apache_request_headers();
        if ($headers['Content-Type'] !== 'application/json; charset=utf-8') {
            $this->send(['data' => 'unknown content type header', 'code' => 400]);
            return;
        }

        // Получить вызываемый метод.
        $handler = $this->routeHandlers[$path];
        if (!isset($handler)) {
            $this->send(['data' => 'unknown method', 'code' => 400]);
            return;
        }

        // Вычленить передаваемые данные.
        [$data, $ok] = $this->parseBodyJSON();
        if (!$ok) {
            $this->send(['data' => 'wrong params', 'code' => 400]);
            return;
        }

        // Провести аутентификацию.
        $userID = (int)$headers['X-USER-ID'];
        $user = $this->dbm->getUser($userID);
        if (!isset($user)) {
            $this->send(['data' => 'not authenticated', 'code' => 400]);
            return;
        }

        // Исполнить вызываемый метод.
        $resp = $handler($data, $user);
        $this->send($resp);
    }

    private function parseBodyJSON() : array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data)) {
            return [[], true];
        }
        switch(json_last_error()) {
        case JSON_ERROR_DEPTH:
            return [[], false];
        case JSON_ERROR_CTRL_CHAR:
            return [[], false];
        case JSON_ERROR_SYNTAX:
            return [[], false];
        }
        return [$data, true];
    }

    // TODO стандартизировать ответы с ошибками, внедрить обертку с ok в ответ.
    private function send(array $resp) : void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($resp['code']);
        echo json_encode($resp['data']);
    }

    private function handlerCreatePost(array $data, User $user) : array
    {
        $postID = $this->dbm->createPost($user->id, $data['text']);
        if ($postID === 0) {
            return ['data' => '', 'code' => 400];
        }
        return ['data' => $postID, 'code' => 200];
    }

    private function handlerGetUserPosts(array $data, User $user) : array
    {
        $posts = $this->dbm->getUserPosts($user->id, (int)$data['last_post_id']);
        return ['data' => $posts, 'code' => 200];
    }

    private function handlerGetPost(array $data, User $user) : array
    {
        $post = $this->dbm->getPost((int)$data['post_id']);
        if (empty($post)) {
            return ['data' => null, 'code' => 400];
        }
        return ['data' => $post, 'code' => 200];
    }

    private function handlerWIP(array $data, User $user) : array
    {
        return ['data' => 'WIP', 'code' => 200];
    }
}