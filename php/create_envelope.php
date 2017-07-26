<?php
// DocuSign JWT PHP example
// Copyright (c) 2017 DocuSign Inc.
// MIT License https://opensource.org/licenses/MIT
//
// JWT library: https://github.com/firebase/php-jwt
use \Firebase\JWT\JWT;

require_once 'vendor/autoload.php'; # http://unirest.io/php.html

// Fill in the following variables:
// Claim items:
// SUB (subscriber) is the user_id of the login that will be impersonated.
// It can be yourself, a "system user", or it can vary (Send on behalf of use case):
// Obtain the user_id via the API or use the Administration tool:
//  1. Open the USERS AND GROUPS/Users screen
//  2. Edit the user
//  3. The field "API Username" is the user's user_id
$sub="[user_id]"; // user's user_id -- email: sue@example.com
// Integrator key (client_id)
$iss="[integration key]";
// The authentication server address. Either account.docusign.com or account-d.docusign.com
$aud="account-d.docusign.com";
// A redirect URI registered for the Integration Key. https://docusign.com is suggested
// It will only be used to enable users to authorize the application
$redirect_uri="https://docusign.com";
// The private key obtained from DocuSign
$private_key_file="keys/docusign_private_key.txt";

// No need to change the following
// The scope for the token requested by the app. Usually signature.
$scope="signature";
// The scopes granted to the app by the user.
// Usually signature and impersonation. Separator is an encoded space (%20)
$permission_scopes="signature%20impersonation";
// Only use RS256 with DocuSign
$alg="RS256";
// Expiry date in seconds from issued at time. Maximum 3600 = 1 hour
$exp=3600;
// url for obtaining a token
$token_url="https://{$aud}/oauth/token";
// Grant type is constant
$grant_type="urn:ietf:params:oauth:grant-type:jwt-bearer";

// JWT Standard docs: https://tools.ietf.org/html/rfc7519
// The standard JWT claim items and their use by DocuSign
// "iss" (Issuer) Claim  . . . . . . . client_id (Integration Key)
// "sub" (Subject) Claim . . . . . . . the user's user_id
// "aud" (Audience) Claim  . . . . . . The authentication server address.
//                                     Either account.docusign.com or account-d.docusign.com
// "exp" (Expiration Time) Claim . . . Seconds validity. DocuSign max is 3600
// "nbf" (Not Before) Claim  . . . . . Current date/time
// "iat" (Issued At) Claim . . . . . . Not used by DocuSign
// "jti" (JWT ID) Claim  . . . . . . . Not used by DocuSign

$current_time = time ();
$token = array(
    "iss" => $iss,
    "sub" => $sub,
    "aud" => $aud,
    "scope" => $scope,
    "nbf" => $current_time,
    "exp" => $current_time + $exp
);
$private_key = file_get_contents ( $private_key_file );
$jwt = JWT::encode($token, $private_key, 'RS256');
printf ("Requesting an access token by using a JWT token...");

// Send the request
$headers = array('Accept' => 'application/json');
$data = array('grant_type' => $grant_type, 'assertion' => $jwt);
$body = Unirest\Request\Body::form($data);
$response = Unirest\Request::post($token_url, $headers, $body);

printf ("done.\nResponse: %s\n\n", $response->raw_body);
printf ("Checking the response...");

// Handle the response if it is an html page
if (strpos($response->raw_body, '<html>') !== false) {
    printf ("An error response was received!\n\n");
    exit (1);
}

// First time for a user: you will receive error return. Eg {"error":"consent_required"}
// In that case, the user needs to explicitly approve access to their credentials.
// Use an OAuth Authorization Code flow to accomplish this. The return_uri must be
// set for the client_id (Integration key) but you do NOT need an app at that location.
// Eg. You can use https://docusign.com as the return_uri if you wish.
$json = $response->body;
if (property_exists ($json, 'error') and $json->{'error'} == 'consent_required' ){
    $consent_url = "https://{$aud}/oauth/auth?response_type=code&scope={$permission_scopes}&client_id={$iss}&redirect_uri={$redirect_uri}";
    printf ("\n\nC O N S E N T   R E Q U I R E D\n");
    printf ("Ask the user who will be impersonated to run the following url:\n");
    printf ("    %s\n", $consent_url);
    printf ("It will ask the user to login and to approve access by your application.\n\n");
    exit (1);
}

// Check for some other error
if (property_exists ($json, 'error') or !property_exists ($json, 'access_token')){
   printf ("\n\nUnexpected error: {$json->{'error'}}\n\n");
   exit (1);
}

$access_token = $json->{'access_token'};
printf ("received access_token!");
$expires = $json->{'expires_in'};
printf (" Expires in %s seconds.\n", $expires);

// Get user information
$user_info_url="https://{$aud}/oauth/userinfo";
printf ("Using /oauth/userinfo to fetch user information...");
$headers = array('Accept' => 'application/json', 'Authorization' => "Bearer {$access_token}");
$response = Unirest\Request::get($user_info_url, $headers);
printf ("done. Results:\n");
$json = $response->body;

// pretty print the response
$pp = json_encode($json, JSON_PRETTY_PRINT);
$pp = str_replace ( '\/' , '/' , $pp );
printf ("%s\n", $pp); //  Don't escape slashes

// Process the response
printf ("\nUsing the first account\n");
$a_name     = $json->{'accounts'}[0]->{'account_name'};
$a_id       = $json->{'accounts'}[0]->{'account_id'};
$a_base_url = $json->{'accounts'}[0]->{'base_uri'};
printf ("Account name: %s\n", $a_name);
printf ("Account ID: %s\n", $a_id);
printf ("Base URL: %s\n", $a_base_url);

// Send the envelope
$doc = "simple_agreement.html";
$payload_file = "create_envelope.json";

//$payload = (object) [
//    'aString' => 'some string',
//    'anArray' => [ 1, 2, 3 ]
//];

$payload = json_decode(file_get_contents($payload_file));
$payload->{'documents'}[0]->{'documentBase64'} = base64_encode(file_get_contents($doc));
//echo ( json_encode($payload, JSON_PRETTY_PRINT));

printf ("Creating the envelope...");
$headers = array('Accept' => 'application/json',
                 'Authorization' => "Bearer {$access_token}",
                 'Content-Type' => 'application/json');
$body = Unirest\Request\Body::json($payload);
$response = Unirest\Request::post("{$a_base_url}/restapi/v2/accounts/{$a_id}/envelopes", $headers, $body);
printf ("done.\n");
$json = $response->body;

if (property_exists ($json, 'errorCode')){
  printf("Error:\n%s\n", json_encode($json, JSON_PRETTY_PRINT));
  exit (1);
}

$env_id = $json->{'envelopeId'};

// Get the recipient view URL
printf ("Envelope ID: %s\n", $env_id);
$name =  "Jackie Williams";  // Same as in create_envelope.json
$email = "jackie@foo.com";   // Same as in create_envelope.json
$client_user_id = "100";     // Same as in create_envelope.json

$payload = (object) [
   'clientUserId' => $client_user_id,
   'email' => $email,
   'userName' => $name,
   'returnUrl' => $redirect_uri,
   'AuthenticationMethod' => 'Password'
];

printf ("Fetching the Signing Ceremony URL...");
$headers = array('Accept' => 'application/json',
                 'Authorization' => "Bearer {$access_token}",
                 'Content-Type' => 'application/json');
$body = Unirest\Request\Body::json($payload);
$response = Unirest\Request::post("{$a_base_url}/restapi/v2/accounts/{$a_id}/envelopes/{$env_id}/views/recipient", $headers, $body);
printf ("done.\n");
$json = $response->body;

if (property_exists ($json, 'errorCode')){
  printf("Error:\n%s\n", json_encode($json, JSON_PRETTY_PRINT));
  exit (1);
}

$view_url = $json->{'url'};
printf ("\nUse this URL to sign the envelope:\n     %s\n\n\nDone.\n", $view_url);

exit (0);
