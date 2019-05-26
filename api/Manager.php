<?php

declare(strict_types=1);

namespace api;

use \db\User;


// TODO вынести ошибки в отдельный файл. Была проблема с подключением ошибок из
// такого же неймспейса, но в другом файле.

// Ошибка: отсутствует ошибка.
const NO_ERR = -1;

// Ошибка: метод находится в разработке.
const ERR_WIP = 777;

// Ошибка: внутренняя ошибка приложения.
const ERR_INTERNAL_ERROR = 500;

// Ошибка: некорректные параметры.
const ERR_WRONG_PARAMS = 400;

// Ошибка: не пройдена аутентификация.
const ERR_NOT_AUTHED = 401;

// Ошибка: некорректный HTTP метод.
const ERR_WRONG_HTTP_METHOD = 400;

// Ошибка: некорректный заголовок Content-Type.
const ERR_WRONG_CONTENT_TYPE = 400;

// Ошибка: попытка вызвать несуществующий метод приложения.
const ERR_WRONG_API_METHOR = 400;

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
            return new Response(ERR_INTERNAL_ERROR, null);
        });


        // Проверить HTTP-метод запроса.
        if ($httpMethod !== 'POST') {
            $this-> send(new Response(ERR_WRONG_HTTP_METHOD, null));
            return;
        }

        // Проверить корректность заголовка Content-Type.
        $headers = apache_request_headers();
        if ($headers['Content-Type'] !== 'application/json; charset=utf-8') {
            $this-> send(new Response(ERR_WRONG_CONTENT_TYPE, null));
            return;
        }

        // Получить вызываемый метод.
        $handler = $this->routeHandlers[$path];
        if (empty($handler)) {
            $this-> send(new Response(ERR_WRONG_API_METHOR, null));
            return;
        }

        // Вычленить передаваемые данные.
        [$data, $ok] = $this->parseBodyJSON();
        if (!$ok) {
            $this-> send(new Response(ERR_WRONG_PARAMS, null));
            return;
        }

        // Провести аутентификацию.
        $userID = (int)$headers['X-USER-ID'];
        $user = $this->dbm->getUser($userID);
        if (empty($user)) {
            $this-> send(new Response(ERR_NOT_AUTHED, null));
            return;
        }

        // Исполнить вызываемый метод.
        $resp = $handler($data, $user);

        // Отправить ответ.
        $this->send($resp);
    }

    private function parseBodyJSON() : array
    {
        $rawData = file_get_contents('php://input');
        if (empty($rawData)) {
            return [[], true];
        }
        $data = json_decode($rawData, true);
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

    private function send(Response $resp) : void
    {
        header('Content-Type: application/json; charset=utf-8');
        switch ($resp->errorCode) {
        case NO_ERR:
            http_response_code(200);
            echo json_encode(['ok' => true, 'data' => $resp->data]);
            return;
        case ERR_INTERNAL_ERROR:
            http_response_code(500);
            echo json_encode(['ok' => false, 'error_code' => $resp->errorCode]);
            return;
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error_code' => $resp->errorCode]);
        }
    }

    private function handlerCreatePost(array $data, User $user) : Response
    {
        $postID = $this->dbm->createPost($user->id, $data['text']);
        if ($postID === 0) {
            return new Response(ERR_WRONG_PARAMS, null);
        }
        return new Response(NO_ERR, ['post_id' => $postID]);
    }

    private function handlerGetUserPosts(array $data, User $user) : Response
    {
        $posts = $this->dbm->getUserPosts($user->id, (int)$data['last_post_id']);
        return new Response(NO_ERR, ['posts' => $posts]);
    }

    private function handlerGetPost(array $data, User $user) : Response
    {
        $post = $this->dbm->getPost((int)$data['post_id']);
        if (empty($post)) {
            return new Response(ERR_WRONG_PARAMS, null);
        }
        return new Response(NO_ERR, ['post' => $post]);
    }

    private function handlerWIP(array $data, User $user) : Response
    {
        return new Response(ERR_WIP, null);
    }
}