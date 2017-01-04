<?php

namespace Drupal\social_auth_twitter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\social_auth_twitter\TwitterAuthManager;
use Drupal\social_api\Plugin\NetworkManager;
use Drupal\social_auth\SocialAuthUserManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Zend\Diactoros\Response\RedirectResponse;

/**
 * Manages requests to Twitter API.
 */
class TwitterAuthController extends ControllerBase {

  /**
   * The network plugin manager.
   *
   * @var \Drupal\social_api\Plugin\NetworkManager
   */
  private $networkManager;

  /**
   * The Twitter authentication manager.
   *
   * @var \Drupal\social_auth_twitter\TwitterAuthManager
   */
  private $twitterManager;

  /**
   * The user manager.
   *
   * @var \Drupal\social_auth\SocialAuthUserManager
   */
  private $userManager;

  /**
   * TwitterLoginController constructor.
   *
   * @param \Drupal\social_api\Plugin\NetworkManager $network_manager
   *   Used to get an instance of social_auth_twitter network plugin.
   * @param \Drupal\social_auth_twitter\TwitterAuthManager $twitter_manager
   *   Used to manage authentication methods.
   * @param \Drupal\social_auth\SocialAuthUserManager $user_manager
   *   Manages user login/registration.
   */
  public function __construct(NetworkManager $network_manager, TwitterAuthManager $twitter_manager, SocialAuthUserManager $user_manager) {
    $this->networkManager = $network_manager;
    $this->twitterManager = $twitter_manager;
    $this->userManager = $user_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.network.manager'),
      $container->get('twitter_auth.manager'),
      $container->get('social_auth.user_manager')
    );
  }

  /**
   * Redirect to Twitter Services Authentication page.
   *
   * @return \Zend\Diactoros\Response\RedirectResponse
   *   Redirection to Twitter Accounts.
   */
  public function redirectToTwitter() {
    /* @var TwitterAuth $network_plugin */
    // Creates an instance of the social_auth_twitter Network Plugin.
    $network_plugin = $this->networkManager->createInstance('social_auth_twitter');

    /* @var TwitterOAuth $connection */
    /* Gets the Twitter SDK.
     *
     * Notice that getSdk() does not require any argument, whereas getSdk2()
     * does.
     *
     * Your social network might not require to have different ways of getting
     * an instance of the SDK, but Twitter does.
     */
    $connection = $network_plugin->getSdk();

    // Requests Twitter to get temporary tokens.
    $request_token = $connection->oauth('oauth/request_token', array('oauth_callback' => $network_plugin->getOauthCallback()));

    // Saves the temporary token values in session.
    $this->twitterManager->setOauthToken($request_token['oauth_token']);
    $this->twitterManager->setOauthTokenSecret($request_token['oauth_token_secret']);

    // Generates url for user authentication.
    $url = $connection->url('oauth/authorize', array('oauth_token' => $request_token['oauth_token']));

    // Redirects the user to allow him to grant permissions.
    return new RedirectResponse($url);
  }

  /**
   * Callback function to login user.
   */
  public function callback() {
    $oauth_token = $this->twitterManager->getOauthToken();
    $oauth_token_secret = $this->twitterManager->getOauthTokenSecret();
    /* @var \Abraham\TwitterOAuth\TwitterOAuth $client */
    $client = $this->networkManager->createInstance('social_auth_twitter')->getSdk2($oauth_token, $oauth_token_secret);

    $this->twitterManager->setClient($client);

    // Gets the permanent access token.
    $access_token = $client->oauth('oauth/access_token', array('oauth_verifier' => $this->twitterManager->getOauthVerifier()));
    $connection = $this->networkManager->createInstance('social_auth_twitter')->getSdk2($access_token['oauth_token'], $access_token['oauth_token_secret']);
    $params = array(
      'include_email' => 'true',
      'include_entities' => 'false',
      'skip_status' => 'true',
    );
    // Gets user information.
    $user = $connection->get("account/verify_credentials", $params);
    // If user information could be retrieved.
    if ($user) {
      // Tries to load the user by his email.
      $drupal_user = $this->userManager->loadUserByProperty('mail', $user->email);
      // If user email has already an account in the site.
      if ($drupal_user) {
        if ($this->userManager->loginUser($drupal_user)) {
          return $this->redirect('user.page');
        }
      }

      $drupal_user = $this->userManager->createUser($user->name, $user->email);
      // If the new user could be registered.
      if ($drupal_user) {
        // If the new user could be logged in.
        if ($this->userManager->loginUser($drupal_user)) {
          return $this->redirect('user.page');
        }
      }
    }
    drupal_set_message($this->t('You could not be authenticated, please contact the administrator'), 'error');
    return $this->redirect('user.login');
  }

}