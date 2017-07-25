#!/bin/bash
#
#
# DocuSign JWT Command line example
# Copyright (c) 2017 DocuSign Inc.
# MIT License https://opensource.org/licenses/MIT

#
#  PREREQUISITES: jwtgen via node and python
#
#  Install jwtgen. It requires node. See https://github.com/vandium-io/jwtgen
#  npm install -g jwtgen
#
#  Python: This shell file needs to parse the response JSON.
#  There are several ways to handle that. See https://stackoverflow.com/a/1955555/64904
#  This file uses the Python technique. If the python command "python" doesn't work
#  then you will need to either install python or change this script.
#

# Fill in the following variables:
# Claim items:
# SUB (subscriber) is the user_id of the login that will be impersonated.
# It can be yourself, a "system user", or it can vary (Send on behalf of use case):
# Obtain the user_id via the API or use the Administration tool:
#  1. Open the USERS AND GROUPS/Users screen
#  2. Edit the user
#  3. The field "API Username" is the user's user_id
# User email: sue@example.com     # this is a note.
SUB="[user_id]" # user's user_id
# Integrator key (client_id)
ISS="[client_id (integration key)]"
# The authentication server address. Either account.docusign.com or account-d.docusign.com
AUD="account-d.docusign.com"
# A redirect URI registered for the Integration Key. https://docusign.com is suggested
# It will only be used to enable users to authorize the application
REDIRECT_URI="https://docusign.com"
# The private key obtained from DocuSign
PRIVATE_KEY_FILE="keys/docusign_private_key.txt"

# No need to change the following
# The scope for the token requested by the app. Usually signature.
SCOPE="signature"
# The scopes granted to the app by the user.
# Usually signature and impersonation. Separator is an encoded space (%20)
PERMISSION_SCOPES="signature%20impersonation"
# Only use RS256 with DocuSign
ALG=RS256
# Expiry date in seconds from issued at time. Maximum 3600 = 1 hour
EXP=3600
# Claims are the json representation of the claims
CLAIMS='{"iss":"'$ISS'","sub":"'$SUB'","aud":"'$AUD'","scope":"'$SCOPE'"}'
# url for obtaining a token
TOKEN_URL="https://"$AUD"/oauth/token"
# Grant type is constant
GRANT_TYPE="urn:ietf:params:oauth:grant-type:jwt-bearer"
export PYTHONIOENCODING=utf8
PY_PREAMBLE="import sys, json; j=json.load(sys.stdin);"
PY_ENSURE_ERROR="j['error'] = 'False' if 'error' not in j else j['error'];"
PY_ENSURE_ACCESS_TOKEN="j['access_token'] = 'False' if 'access_token' not in j else j['access_token'];"

# JWT Standard docs: https://tools.ietf.org/html/rfc7519
# The standard JWT claim items and their use by DocuSign
# "iss" (Issuer) Claim  . . . . . . . client_id (Integration Key)
# "sub" (Subject) Claim . . . . . . . the user's user_id
# "aud" (Audience) Claim  . . . . . . The authentication server address.
#                                     Either account.docusign.com or account-d.docusign.com
# "exp" (Expiration Time) Claim . . . Seconds validity. DocuSign max is 3600
# "nbf" (Not Before) Claim  . . . . . Current date/time
# "iat" (Issued At) Claim . . . . . . Not used by DocuSign
# "jti" (JWT ID) Claim  . . . . . . . Not used by DocuSign

#printf "Create the JWT:\n"
JWT=$(jwtgen --algorithm $ALG \
  --private $PRIVATE_KEY_FILE \
  --claims $CLAIMS \
  --exp $EXP ) # The jwtgen generator uses the current time as the JWT's nbf value
#printf "JWT: %s\n" "$JWT"

printf "Requesting an access token by using a JWT token..."
TOKEN_REQ='grant_type='$GRANT_TYPE'&assertion='$JWT
# Send the data to curl using standard input
TOKEN_RESPONSE=`echo $TOKEN_REQ | curl -s -X POST -d @- $TOKEN_URL`
printf "done.\nResponse: %s\n" "$TOKEN_RESPONSE"
printf "Checking the response..."

# Handle the response if there's a problem with the curl request
if grep -q "Bad Request" <<<"$TOKEN_RESPONSE"; then
    printf 'The DocuSign Authentication server returned "Bad Request."\n'
    printf "Check that your JWT parameters are correct.\n"
    exit 1
fi

# Handle the response if it is an html page
if grep -q "<html>" <<<"$TOKEN_RESPONSE"; then
    printf "Error response:\n%s\n" "$TOKEN_RESPONSE"
    exit 1
fi

# First time for a user: you will receive error return. Eg {"error":"consent_required"}
# In that case, the user needs to explicitly approve access to their credentials.
# Use an OAuth Authorization Code flow to accomplish this. The return_uri must be
# set for the client_id (Integration key) but you do NOT need an app at that location.
# Eg. You can use https://docusign.com as the return_uri if you wish.
CONSENT_REQ=`echo $TOKEN_RESPONSE|python -c "$PY_PREAMBLE $PY_ENSURE_ERROR print j['error']=='consent_required'"`
if [ "$CONSENT_REQ" == 'True' ]; then
  CONSENT_URL="https://"$AUD"/oauth/auth?response_type=code&scope="$PERMISSION_SCOPES"&client_id="$ISS"&redirect_uri="$REDIRECT_URI
  printf "\n\nC O N S E N T   R E Q U I R E D\n"
  printf "Ask the user who will be impersonated to run the following url:\n"
  printf "    %s\n" $CONSENT_URL
  printf "It will ask the user to login and to approve access by your application.\n\n"
  exit 1
fi

TOKEN_ERR=`echo $TOKEN_RESPONSE|python -c "$PY_PREAMBLE $PY_ENSURE_ERROR print j['error']"`
if [ "$TOKEN_ERR" == 'True' ]; then
  printf "\n\nUnexpected error\n"
  printf "%s\n" $TOKEN_ERR
  exit 1
fi

ACCESS_TOKEN=`echo $TOKEN_RESPONSE|python -c "$PY_PREAMBLE $PY_ENSURE_ACCESS_TOKEN print j['access_token']"`
if [ "$ACCESS_TOKEN" != 'False' ]; then
  printf "received access_token!"
  EXPIRES=`echo $TOKEN_RESPONSE|python -c "$PY_PREAMBLE $PY_ENSURE_ACCESS_TOKEN print j['expires_in']"`
  printf " Expires in %s seconds.\n" "$EXPIRES"
else
  printf "\n\nUnexpected problem. DocuSign response:\n"
  printf "%s\n" $TOKEN_RESPONSE
  exit 1
fi

USER_INFO="/oauth/userinfo"
# Get login information
printf "Using /oauth/userinfo to fetching user information..."
USERINFO=`curl -s -H "Authorization: Bearer "$ACCESS_TOKEN "https://"$AUD$USER_INFO`
printf "done. Results:\n"

# pretty print the response
PP=`echo $USERINFO|python -c "$PY_PREAMBLE print json.dumps(j,sort_keys=True,indent=4,separators=(',',': '))"`
printf "%s\n" "$PP"

# Process the response
printf "\nUsing the first account\n"
ACCNT_NAME=`echo $USERINFO|python -c "$PY_PREAMBLE print j['accounts'][0]['account_name']"`
ACCNT_ID=`echo $USERINFO|python -c "$PY_PREAMBLE print j['accounts'][0]['account_id']"`
ACCNT_BASE_URL=`echo $USERINFO|python -c "$PY_PREAMBLE print j['accounts'][0]['base_uri']"`
printf "Account name: %s\n" "$ACCNT_NAME"
printf "Account ID: %s\n" "$ACCNT_ID"
printf "Base URL: %s\n" "$ACCNT_BASE_URL"

# Send the envelope
DOC="simple_agreement.html"
STEP1_REQ="create_envelope.json"
DOC_BASE64="doc.base64"
PAYLOAD="payload.json"
base64 $DOC -o "$DOC_BASE64" # base64 encode the doc
# insert the doc into the json file to create the payload file...
sed -e "s/FILE1_BASE64/$(sed 's:/:\\/:g' $DOC_BASE64)/" $STEP1_REQ > "$PAYLOAD"
printf "Creating the envelope..."
ENV_RESULTS=`curl -s -X POST -d @${PAYLOAD} \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer "$ACCESS_TOKEN \
  $ACCNT_BASE_URL/restapi/v2/accounts/$ACCNT_ID/envelopes`
printf "done.\n"
ENVELOPE_ID=`echo $ENV_RESULTS|python -c "$PY_PREAMBLE print j['envelopeId']"`

# Get the recipient view URL
printf "Envelope ID: %s\n" "$ENVELOPE_ID"
NAME="Jackie Williams"  # Same as in create_envelope.json
EMAIL="jackie@foo.com"  # Same as in create_envelope.json
CLIENT_USER_ID="100"    # Same as in create_envelope.json
VIEW_PAYLOAD='{"clientUserId":"'$CLIENT_USER_ID'","email":"'$EMAIL'","userName":"'$NAME'","returnUrl": "'$REDIRECT_URI'","AuthenticationMethod": "Password"}'
printf "Fetching recipient view url..."
VW_RESULTS=`curl -s -X POST -d "$VIEW_PAYLOAD" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer "$ACCESS_TOKEN \
  $ACCNT_BASE_URL/restapi/v2/accounts/$ACCNT_ID/envelopes/$ENVELOPE_ID/views/recipient`
VIEW_URL=`echo $VW_RESULTS|python -c "$PY_PREAMBLE print j['url']"`
printf "\nUse this URL to sign the envelope:\n     %s\n\n\nDone.\n" "$VIEW_URL"


exit 0
