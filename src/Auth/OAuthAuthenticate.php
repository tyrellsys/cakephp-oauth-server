<?php
declare(strict_types=1);

namespace OAuthServer\Auth;

use Cake\Auth\BaseAuthenticate;
use Cake\Controller\ComponentRegistry;
use Cake\Core\Configure;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use OAuthServer\Bridge\ResourceServerFactory;

class OAuthAuthenticate extends BaseAuthenticate
{
    /**
     * @var \League\OAuth2\Server\ResourceServer
     */
    protected $Server;

    /**
     * Exception that was thrown by oauth server
     *
     * @var \League\OAuth2\Server\Exception\OAuthServerException
     */
    protected $_exception;

    /**
     * @var array
     */
    protected $_defaultConfig = [
        'continue' => false,
        'publicKey' => null,
        'fields' => [
            'username' => 'id',
        ],
        'userModel' => 'Users',
        'scope' => [],
        'finder' => 'all',
        'contain' => null,
    ];

    /**
     * @param \Cake\Controller\ComponentRegistry $registry Component registry
     * @param array $config Config array
     */
    public function __construct(ComponentRegistry $registry, $config)
    {
        parent::__construct($registry, $config);

        if ($this->getConfig('server')) {
            $this->setServer($this->getConfig('server'));

            return;
        }
    }

    /**
     * @return \League\OAuth2\Server\ResourceServer
     */
    protected function getServer(): ResourceServer
    {
        if (!$this->Server) {
            $serverFactory = new ResourceServerFactory(
                $this->getConfig('publicKey', Configure::read('OAuthServer.publicKey'))
            );

            $this->setServer($serverFactory->create());
        }

        return $this->Server;
    }

    /**
     * @param \League\OAuth2\Server\ResourceServer $Server the ResourceServer instance
     * @return void
     */
    public function setServer(ResourceServer $Server): void
    {
        $this->Server = $Server;
    }

    /**
     * Authenticate a user based on the request information.
     *
     * @param \Cake\Http\ServerRequest $request Request to get authentication information from.
     * @param \Cake\Http\Response $response A response object that can have headers added.
     * @return mixed
     */
    public function authenticate(ServerRequest $request, Response $response)
    {
        return $this->getUser($request);
    }

    /**
     * @param \Cake\Http\ServerRequest $request Request to get authentication information from.
     * @param \Cake\Http\Response $response A response object that can have headers added.
     * @return bool|void
     */
    public function unauthenticated(ServerRequest $request, Response $response)
    {
        if ($this->getConfig('continue')) {
            return false;
        }

        if (isset($this->_exception)) {
            throw new UnauthorizedException(
                $this->_exception->getMessage(),
                $this->_exception->getHttpStatusCode(),
                $this->_exception
            );
        }

        $message = __d('authenticate', 'You are not authenticated.');
        throw new BadRequestException($message);
    }

    /**
     * @param \Cake\Http\ServerRequest|\OAuthServer\Auth\ServerRequestInterface $request Request object
     * @return array|bool|mixed
     */
    public function getUser(ServerRequest $request)
    {
        try {
            $request = $this->getServer()->validateAuthenticatedRequest($request);
        } catch (OAuthServerException $e) {
            $this->_exception = $e;

            return false;
        }

        $userId = $request->getAttribute('oauth_user_id');

        $result = $this->_query($userId)->first();

        if (empty($result)) {
            return false;
        }

        return $result->toArray();
    }
}
