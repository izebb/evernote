<?php

    define("EVERNOTE_LIBS", "");
    ini_set("include_path", ini_get("include_path") . PATH_SEPARATOR . EVERNOTE_LIBS);
    require_once 'Evernote/Client.php';
    require_once 'packages/Types/Types_types.php';
    // Import the classes that we're going to be using
    use EDAM\Error\EDAMSystemException,
        EDAM\Error\EDAMUserException,
        EDAM\Error\EDAMErrorCode,
        EDAM\Error\EDAMNotFoundException;
    use Evernote\Client;
// $GLOBALS['THRIFT_ROOT']  =__DIR__."/vendor/evernote/evernote/lib";
class EvernoteLoader
{
	private $config;
	private $lastError = null;
	private $currentStatus = null;
	private $notebooks;
	private $accessToken;
	private $userId;
    private $tokenExpires;
    private $sandbox;
	private $client;
    private $apiKey;
    private $secretKey;
    private $callbackUrl;

	public function __construct( $apiKey, $secretKey,  $callbackUrl, $sandbox = FALSE, $securitySalt = NULL)
	{	
        $this->sandbox= $sandbox;
        $this->apiKey = $apiKey;
        $this->callbackUrl = $callbackUrl;

        $this->secretKey = $secretKey;
		$config = array();

	}

    public function  getClient(){
        $client = new Client(array(
                'token' => $this->accessToken,
        ));
        return $client;
    }

    public function getTemporaryCredentials(){
        try {
            $client = new Client(array(
                'consumerKey' => $this->apiKey,
                'consumerSecret' => $this->secretKey,
            ));
            $requestTokenInfo = $client->getRequestToken( $this->callbackUrl."?oauth_action=callback" );
            if ($requestTokenInfo) {
                $_SESSION['requestToken'] = $requestTokenInfo['oauth_token'];
                $_SESSION['requestTokenSecret'] = $requestTokenInfo['oauth_token_secret'];
                $this->currentStatus = 'Obtained temporary credentials';
                return TRUE;

            } else {
                $this->lastError = 'Failed to obtain temporary credentials.';
            }
        } catch (OAuthException $e) {
            $this->lastError = 'Error obtaining temporary credentials: ' . $e->getMessage();
        }

        return FALSE;
    }

    public function getAuthorizationUrl()
    {
        $client = new Client(array(
                'consumerKey' => $this->apiKey,
                'consumerSecret' => $this->secretKey,
        ));
        return $client->getAuthorizeUrl($_SESSION['requestToken']);
    }

    public function getTokenCredentials()
    {

       
        try {
            $client = new Client(array(
                'consumerKey' => $this->apiKey,
                'consumerSecret' => $this->secretKey,
            ));
            $accessTokenInfo = $client->getAccessToken($_SESSION['requestToken'], $_SESSION['requestTokenSecret'], $_SESSION['oauthVerifier']);
            if ($accessTokenInfo) {
                $_SESSION['accessToken'] = $accessTokenInfo['oauth_token'];
                $this->accessToken = $_SESSION['accessToken'];
                $this->currentStatus = 'Exchanged the authorized temporary credentials for token credentials';
                return TRUE;
            } else {
                $this->lastError = 'Failed to obtain token credentials.';
            }
        } catch (OAuthException $e) {
            $this->lastError = 'Error obtaining token credentials: ' . $e->getMessage();
        }

        return FALSE;
    }

	

	public function isAuthorized()
	{
        return ( isset($_SESSION['accessToken']) ) ?  TRUE: FALSE;
	}

    public function oauthReady(){
            if( isset($_GET['oauth_action'])  && $_GET['oauth_action'] == "callback" ){
                $this->getTokenCredentials();
            }else{
                $this->getTemporaryCredentials();
                header('Location: ' . $this->getAuthorizationUrl());
            }

    }
	public function authorize()
	{
        if($this->isAuthorized() ){
            $this->accessToken = $_SESSION['accessToken'];
           $this->client = $this->run('getClient');
            return TRUE;
        }
        $this->oauthReady();
        $this->client = $this->run('getClient');
	}

	public function getLastError(){
		return $this->lastError;
	}

	public  function getCurrentStatus(){
		return $this->currentStatus;
	}

    public function getResources( $noteGuid )
    {
        $resources = $this->getNote( $noteGuid, 'resources');
        return   $resources;
    }

    public function getAllTodoList()
    {
        $notebooks  = $this->listNotebooks();
        $todo = array();
        foreach ($notebooks as $notebook) {
            $notebk = $this->findNotes($notebook->guid);
            foreach ($notebk->notes as $note ) {
                $resources = $this->getTodo($note->guid);
                if(!empty($resources)){
                    $todo[] = $resources;
                }
            }
        }
        return $todo;
    }

    public function getAllNotes($all=false)
    {

        $notebooks  = $this->listNotebooks();
        $allNotes = array();
        foreach ($notebooks as $notebook) 
        {
            $notebk = $this->findNotes( $notebook->guid );
            foreach ( $notebk->notes as $note ) {
                $nt = $this->getNote($note->guid);
                if(!empty($nt)){
                    if(!empty($nt->content)){
                        if($all){
                            $allNotes[] = $nt;
                        }else{
                            $allNotes[] = array('title'=>$nt->title, 'content'=>strip_tags($nt->content), 'raw'=>$nt->content );
                        }

                    }

                }
            }

        }
        return $allNotes;
    }

    public function addNoteToNotebook( $content, $noteBook )
    {
        $newNote = new \EDAM\Types\Note();
        $nBody = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>";
        $nBody .= "<!DOCTYPE en-note SYSTEM \"http://xml.evernote.com/pub/enml2.dtd\">";
        $nBody .= $content;



    }
    public function createNoteBook( $name )
    {
        $this->run(function( $name ){
            $noteBk = $this->noteBookExist($name );
            if(!$noteBk ){
                $newNotebook = new \EDAM\Types\Notebook();
                $newNotebook->name= $name;
                $newNotebook->default= true;
                $newNotebk = $this->client->getNoteStore()->createNotebook($this->accessToken, $newNotebook );
                return $newNotebk;
            }
            return $noteBk;
        }, $name );
        
    }
    public function getNoteContent( $noteGuid )
    {
        return $this->getNote( $noteGuid, 'content' );
    }

    public function getTodo( $noteGuid )
    {
        $notes = $this->getNote( $noteGuid, 'content' );
        if(!empty($notes )){
            $notesContent  = html_entity_decode( $notes );
            $notesContent =  str_replace( array("&amp;", "&"), array("&", "&amp;"), $notesContent);
            $xml = simplexml_load_string( $notesContent );
            $content = array();
            foreach( $xml->div  as $elem ){
                if( $elem->{'en-todo'} ){
                    $_elem = (string)$elem;
                    $content[] = array('content'=> ucfirst( $_elem ));
                }
            }
            return array_values($content);
        }
    }

    public function getNoteStore(){
        return  $this->client->getNoteStore();
    }

    public function getNote( $noteGuid, $property = NULL,  $withContent = TRUE, $withResourcesData = TRUE, $withResourcesRecognition = FALSE, $withResourcesAlternateData = FALSE )
    {
        return $this->run(function( $noteGuid,$withContent,$withResourcesData, $withResourcesRecognition, $withResourcesAlternateData ) use ($property){
            $clientNotes = $this->client->getNoteStore()->getNote($noteGuid,$withContent,$withResourcesData, $withResourcesRecognition, $withResourcesAlternateData );
            return !is_null($property) ? $clientNotes->$property :  $clientNotes;

        }, array($noteGuid,$withContent,$withResourcesData, $withResourcesRecognition, $withResourcesAlternateData ) );
    } 

    public function data_uri($contents, $mime) 
    {  
      $base64   = base64_encode($contents); 
      return ('data:' . $mime . ';base64,' . $base64);
    }
   public  function findNotes( $guid, $minLimit=0, $maxLimit =100 )
   {
        return $this->run(function( $guid, $minLimit, $maxLimit ){
            $filter = new EDAM\NoteStore\NoteFilter();
            $filter->notebookGuid = $guid;
            $spec = new EDAM\NoteStore\NotesMetadataResultSpec();
            $notes = $this->client->getNoteStore()->findNotesMetadata($this->accessToken, $filter, $minLimit, $maxLimit,  $spec  );
            return $notes;

        }, array($guid, $minLimit, $maxLimit));

   }


   public function noteBookExist( $name )
   {
    
    $this->run(function( $name ){
        $exits = false;
        $noteBooks = $this->client->getNoteStore()->listNotebooks();
        foreach ($noteBooks as $notebook )
        {
            if(strtolower($notebook->name) ==  strtolower($name) ){
                $exits  =  $notebook;
                break;
            }
        }
        return $exits;
    },array( $name ));
     
   }

    public function listNotebooks()
    {
        return $this->run(function(){
            $notebooks = $this->client->getNoteStore()->listNotebooks();
            $this->notebooks = $notebooks;
            $this->currentStatus = 'notebooks';
            return  $this->notebooks;
        });
    }

	public function reset()
	{
		 if (isset($_SESSION['opauth'])) {
            unset($_SESSION['opauth']);
        }
        if (isset($_SESSION['requestToken'])) {
            unset($_SESSION['requestToken']);
        }
        if (isset($_SESSION['requestTokenSecret'])) {
            unset($_SESSION['requestTokenSecret']);
        }
        if (isset($_SESSION['oauthVerifier'])) {
            unset($_SESSION['oauthVerifier']);
        }
        if (isset($_SESSION['opauth_accessToken'])) {
            unset($_SESSION['opauth_accessToken']);
        }
        if (isset($_SESSION['accessTokenSecret'])) {
            unset($_SESSION['accessTokenSecret']);
        }
        if (isset($_SESSION['noteStoreUrl'])) {
            unset($_SESSION['noteStoreUrl']);
        }
        if (isset($_SESSION['webApiUrlPrefix'])) {
            unset($_SESSION['webApiUrlPrefix']);
        }
        if (isset($_SESSION['tokenExpires'])) {
            unset($_SESSION['tokenExpires']);
        }
        if (isset($_SESSION['opauth_userId'])) {
            unset($_SESSION['opauth_userId']);
        }
	}



    public function run( $callable, $args = array() ){

        try{
            if(is_callable( $callable))
                return call_user_func_array($callable, $args);
            else{
                if(method_exists($this, $callable))
                    return $this->$callable();
            }
        }
        catch (EDAMSystemException $e) {
                if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
                    $this->lastError = 'Error listing notebooks: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
                } else {
                    $this->lastError = 'Error listing notebooks: ' . $e->getCode() . ": " . $e->getMessage();
                }
            } catch (EDAMUserException $e) {
                if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
                    $this->lastError = 'Error listing notebooks: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
                } else {
                    $this->lastError = 'Error listing notebooks: ' . $e->getCode() . ": " . $e->getMessage();
                }
            } catch (EDAMNotFoundException $e) {
                if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
                    $this->lastError = 'Error listing notebooks: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
                } else {
                    $this->lastError = 'Error listing notebooks: ' . $e->getCode() . ": " . $e->getMessage();
                }
            } catch (Exception $e) {
                $this->lastError = 'Error listing notebooks: ' . $e->getMessage();
        }
        return FALSE;
   }

}

  


?>