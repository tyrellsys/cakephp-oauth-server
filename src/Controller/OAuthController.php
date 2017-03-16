<?php
namespace OAuthServer\Controller;

use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\Time;
use Cake\Network\Exception\HttpException;
use Cake\Network\Response;
use League\OAuth2\Server\Exception\AccessDeniedException;
use League\OAuth2\Server\Exception\OAuthException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Util\RedirectUri;

/**
 * Class OAuthController
 *
 * @property \OAuthServer\Controller\Component\OAuthComponent $OAuth
 */
class OAuthController extends AppController
{
    /**
     * @return void
     */
    public function initialize()
    {
        parent::initialize();

        if (!$this->components()->has('Auth')) {
            throw new \RuntimeException("OAuthServer requires Auth component to be loaded and properly configured");
        }

        $this->loadComponent('OAuthServer.OAuth', (array)Configure::read('OAuthServer'));
        $this->loadComponent('RequestHandler');
    }

    /**
     * @param \Cake\Event\Event $event Event object.
     * @return void
     */
    public function beforeFilter(Event $event)
    {
        parent::beforeFilter($event);

        $this->Auth->allow(['oauth', 'authorize', 'accessToken']);
    }

    /**
     * @return void
     */
    public function oauth()
    {
        $this->redirect([
            'action' => 'authorize',
            '_ext' => $this->request->param('_ext'),
            '?' => $this->request->query
        ], 301);
    }

    /**
     * @throws \League\OAuth2\Server\Exception\InvalidGrantException
     * @return Response|null
     */
    public function authorize()
    {
    /** @var AuthCodeGrant $authCodeGrant */
        try {
            $authCodeGrant = $this->OAuth->Server->getGrantType('authorization_code');
            $authParams = $authCodeGrant->checkAuthorizeParams();
        } catch (OAuthException $e) {
            // ignoring $e->getHttpHeaders() for now
            // it only sends add WWW-Authenticate header in case of InvalidClientException
            throw new HttpException($e->getMessage(), $e->httpStatusCode, $e);
        }

        if (!$this->Auth->user()) {
            $this->Auth->redirectUrl($this->request->here(false));

            return $this->redirect($this->Auth->config('loginAction'));
        }

        $ownerModel = $this->request->query('owner_model') ?: 'Users';
        $ownerId = $this->request->query('owner_id') ?: $this->Auth->user('id');
        $clientId = $this->request->query('client_id');

        $currentTokens = $this->loadModel('OAuthServer.AccessTokens')
            ->find()
            ->where(['expires > ' => Time::now()->getTimestamp()])
            ->matching('Sessions', function ($q) use ($ownerModel, $ownerId, $clientId) {
                return $q->where([
                    'owner_model' => $ownerModel,
                    'owner_id' => $ownerId,
                    'client_id' => $clientId
                ]);
            })
            ->count();

        $event = new Event('OAuthServer.beforeAuthorize', $this);
        EventManager::instance()->dispatch($event);

        $serializeKeys = [];
        if (is_array($event->result)) {
            $this->set($event->result);
            $serializeKeys = array_keys($event->result);
        }


        if ($currentTokens > 0 || ($this->request->is('post') && $this->request->data('authorization') === 'Approve')) {
            $ownerModel = $this->request->data('owner_model') ?: $ownerModel;
            $ownerId = $this->request->data('owner_id') ?: $ownerId;
            $redirectUri = $this->OAuth->Server->getGrantType('authorization_code')->newAuthorizeRequest($ownerModel, $ownerId, $authParams);
            $event = new Event('OAuthServer.afterAuthorize', $this);
            EventManager::instance()->dispatch($event);

            return $this->redirect($redirectUri);
        } elseif ($this->request->is('post')) {
            $event = new Event('OAuthServer.afterDeny', $this);
            EventManager::instance()->dispatch($event);

            $error = new AccessDeniedException();

            $redirectUri = RedirectUri::make($authParams['redirect_uri'], [
                'error' => $error->errorType,
                'message' => $error->getMessage()
            ]);

            return $this->redirect($redirectUri);
        }

        $this->set('authParams', $authParams);
        $this->set('user', $this->Auth->user());
        $this->set('_serialize', array_merge(['user', 'authParams'], $serializeKeys));

        return null;
    }

    /**
     * @return void
     */
    public function accessToken()
    {
        try {
            $response = $this->OAuth->Server->issueAccessToken();
            $this->set($response);
            $this->set('_serialize', array_keys($response));
        } catch (OAuthException $e) {
            // ignoring $e->getHttpHeaders() for now
            // it only sends WWW-Authenticate header in case of InvalidClientException
            throw new HttpException($e->getMessage(), $e->httpStatusCode, $e);
        }
    }
}
