<?php

// JWT library: https://github.com/firebase/php-jwt
use \Firebase\JWT\JWT;

require_once 'vendor/autoload.php'; # http://unirest.io/php.html

Class Docusign {

  /**
   * @var string $guid
   *   Subscriber, i.e. 'Api Username' in the Docusign admin console.
   */
  protected $guid;

  /**
   * Set GUID, or API username.
   *
   * @param string $guid
   */
  private function setGuid($guid) {
    $this->guid = $guid;
  }

  /**
   * @var string $integrator
   *   Application integrator key, aka 'iss'.
   */
  protected $integrator;

  /**
   * Set integrator key.
   *
   * @param string $key
   */
  private function setIntegrator($key) {
    $this->integrator = $key;
  }

  /**
   * @var string $redirect_uri
   */
  protected $redirect_uri;

  /**
   * Set redirect URI.
   *
   * @param string $uri
   */
  private function setRedirectUri($uri) {
    $this->redirect_uri = $uri;
  }

  /**
   * @var string $private_key
   */
  protected $private_key;

  /**
   * @var string $host
   *    Docusign host: either "account-d.docusign.com" for development, or "account.docusign.com" for production.
   */
  protected $host;

  /**
   * @var string $scope
   *   Docusign default is 'signature' for JWT.
   */
  protected $scope;

  /**
   * @var string $permission_scopes
   *   Docusign default is 'signature%20impersonation' for JWT.
   */
  protected $permission_scopes;

  /**
   * @var string $alg
   *   Docusign default is 'RS256'.
   */
  protected $alg;

  /**
   * @var number $expires
   *   Docusign default is 3600.
   */
  protected $expires;

  /**
   * @var string $grant_type
   *   Docusign default is 'urn:ietf:params:oauth:grant-type:jwt-bearer'.
   */
  protected $grant_type;

  /**
   * @var string $access_token
   *   This is returned from JWT authentication routine.
   */
  protected $access_token;

  /**
   * Setter
   *
   * @var string $token
   */
  protected function setAccessToken($token) {
    $this->access_token = $token;
  }

  /**
   * @var string $account_id
   *   This is returned from JWT authentication routine.
   */
  protected $account_id;

  /**
   * Setter
   *
   * @var string $accountId
   */
  protected function setAccountId($accountId) {
    $this->accound_id = $accountId;
  }

  /**
   * Constructor
   *
   * @param array $config
   *   Array of configuration values.
   */
  public function __construct($config) {
    $this->scope = 'signature';
    $this->permission_scopes = 'signature%20impersonation';
    $this->alg = 'RS256';
    $this->expires = 3600;
    $this->grant_type = "urn:ietf:params:oauth:grant-type:jwt-bearer";
    $this->host = 'account-d.docusign.com'; // Change this for production.
    $this->integrator = $config->integrator;
    $this->guid = $config->guid;
    $this->redirect_uri = $config->redirect_uri;
    $this->private_key = file_get_contents('keys/docusign_private_key.txt');
  }

  /**
   * Get user information from Docusign.
   *
   * @return string
   */
  public function getUserInfo() {
    if (!isset($this->access_token)) {
      $auth = $this->authenticate();
    }

    $user_info_url = "https://{$this->host}/oauth/userinfo";
    $headers = $this->getHeaders($this->access_token);
    $response = Unirest\Request::get($user_info_url, $headers);
    $json = $response->body;
    return $json;
  }

  /**
   *
   */
  public function getDefaultAccount() {
    $users = $this->getUserInfo();
    foreach($users->accounts as $account) {
      if ($account->is_default == 1) return $account;
    }
  }

  /**
   * Get envelopes.
   *
   * @param string $accountId
   *   Account ID that is returned from Docusign.
   * @param array $queryParams
   *   Pass query params to Docusign, i.e. 'from_date', 'status'
   *
   * @return string
   */
  public function getEnvelopes($accountId, $queryParams = []) {
    if (!isset($this->access_token)) {
      $auth = $this->authenticate();
    }

    // Docusign forces us to send _some_ params; the most basic is from_date.
    if (!isset($queryParams['from_date'])) {
      $queryParams['from_date'] = date('Y-m-d G:i', strtotime("-1 days"));
    }

    $url = "https://demo.docusign.net/restapi/v2/accounts/${accountId}/envelopes";
    $headers = $this->getHeaders($this->access_token);
    $params = $queryParams;
    $response = Unirest\Request::get($url, $headers, $params);
    $json = $response->body;
    return $json;
  }

  /**
   * TODO: this does not allow for date. Do we want to allow that?
   *
   * @param string $accountId
   *   Account ID that is returned from Docusign.
   * @param string $status
   *   Valid states are: 'completed', 'created, 'declined', 'deleted, 'delivered', 'processing', 'sent', 'signed', 'timedout', 'voided', 'any'.
   */
  public function getEnvelopesStatus($accountId, $status) {
    return $this->getEnvelopes($accountId, ['status' => $status]);
  }

  /**
   *
   * @param string $accountId
   *   Account ID that is returned from Docusign.
   * @param string $envelopeId
   *
   * @return string
   */
  public function getEnvelopeById($accountId, $envelopeId) {
    if (!isset($this->access_token)) {
      $auth = $this->authenticate();
    }

    $url = "https://demo.docusign.net/restapi/v2/accounts/${accountId}/envelopes/${envelopeId}/form_data";
    $headers = $this->getHeaders($this->access_token);
    $response = Unirest\Request::get($url, $headers);
    $json = $response->body;
    return $json;
  }

  /**
   * Get powerforms.
   *
   * @param string $accountId
   *   Account ID that is returned from Docusign.
   *
   * @return string
   */
  public function getPowerforms($accountId) {
    if (!isset($this->access_token)) {
      $auth = $this->authenticate();
    }

    $url = "https://demo.docusign.net/restapi/v2/accounts/${accountId}/powerforms";
    $headers = $this->getHeaders($this->access_token);
    $response = Unirest\Request::get($url, $headers);
    $json = $response->body;
    return $json;
  }

  /**
   * Generate a JWT to make requests.
   *
   * @return string
   */
  private function getJwt() {
    $current_time = time();

    $token = [
        "iss" => $this->integrator,
        "sub" => $this->guid,
        "aud" => $this->host,
        "scope" => $this->scope,
        "nbf" => $current_time,
        "exp" => $current_time + $this->expires
    ];

    $jwt = JWT::encode($token, $this->private_key, 'RS256');
    return $jwt;
  }

  /**
   * Authenticate against API.
   *
   * @return string || NULL
   */
  public function authenticate() {
    $jwt = $this->getJwt();

    if ($jwt) {
      $body = Unirest\Request\Body::form($this->getDataBody($jwt));
      $tokenUrl = "https://{$this->host}/oauth/token";
      $response = Unirest\Request::post($tokenUrl, $this->getHeaders(), $body);

      if (strpos($response->raw_body, '<html>') !== false) {
        print_r('An error occured while getting the JWT from Docusign.');
        return;
      }
      // First time, we need to authenticate via Docusign website.
       else if (property_exists($response->body, 'error')) {
        $this->handleError($response);
      } else {
        // return $jwt;
        $body = $response->body;
        $this->setAccessToken($body->access_token);
        return $body;
      }
    } else {
      printf("Couldn't generate the JWT\n");
    }
  }

  /**
   * To set up the first time, we need to manually authenticate via Docusign website.
   * TODO: this would need to send a notification via Drupal, email, something else.
   *
   * @param Unirest\Response object $response
   */
  private function handleError($response) {
    $json = $response->body;
    if ($json->{'error'} == 'consent_required') {
      $consent_url = "https://{$this->host}/oauth/auth?response_type=code&scope={$this->permission_scopes}&client_id={$this->integrator}&redirect_uri={$this->redirect_uri}";
      printf ("\n\nC O N S E N T   R E Q U I R E D\n");
      printf ("Ask the user who will be impersonated to run the following url:\n");
      printf ("    %s\n", $consent_url);
      printf ("It will ask the user to login and to approve access by your application.\n\n");
    } else {
      printf ("\n\nUnexpected error: {$json->{'error'}}\n\n");
    }
  }

  /**
   * Get defaults.
   * Use without token to authenticate. Use with token for data requests.
   *
   * @param string $access_token
   *
   * @return array
   */
  private function getHeaders($access_token = NULL) {
    $headers = [
      'Accept' => 'application/json'
    ];
    if ($access_token) {
      $headers['Authorization'] = "Bearer ${access_token}";
    }
    return $headers;
  }

  /**
   * Get defaults.
   *
   * @param string $jwt
   *
   * @return array
   */
  private function getDataBody($jwt) {
    return [
      'grant_type' => $this->grant_type,
      'assertion' => $jwt
    ];
  }

}