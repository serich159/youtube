<?php

namespace Dawson\Youtube;

use Exception;
use Carbon\Carbon;
use Google_Client;
use Google_Service_YouTube;
use Illuminate\Support\Facades\DB;

class Youtube
{
    /**
     * Application Container
     *
     * @var Application
     */
    private $app;

    /**
     * Google Client
     *
     * @var \Google_Client
     */
    protected $client;

    /**
     * Google YouTube Service
     *
     * @var \Google_Service_YouTube
     */
    protected $youtube;

    /**
     * Video ID
     *
     * @var string
     */
    private $videoId;

    /**
     * Video Snippet
     *
     * @var array
     */
    private $snippet;

    /**
     * Thumbnail URL
     *
     * @var string
     */
    private $thumbnailUrl;

    /**
     * Constructor
     *
     * @param \Google_Client $client
     */
    public function __construct($app, Google_Client $client)
    {
        $this->app = $app;

        $this->client = $this->setup($client);

        $this->youtube = new \Google_Service_YouTube($this->client);
    }

    /**
     * Upload the video to YouTube
     *
     * @param  string $path
     * @param  array  $data
     * @param  string $privacyStatus
     * @return string
     */
    public function upload($path, array $data = [], $privacyStatus = 'public', $user_id = null)
    {
        if (!file_exists($path)) {
            throw new Exception('Video file does not exist at path: "'. $path .'". Provide a full path to the file before attempting to upload.');
        }

        $this->handleAccessToken($user_id);

        try {
            // Setup the Snippet
            $snippet = new \Google_Service_YouTube_VideoSnippet();

            if (array_key_exists('title', $data)) {
                $snippet->setTitle($data['title']);
            }
            if (array_key_exists('description', $data)) {
                $snippet->setDescription($data['description']);
            }
            if (array_key_exists('tags', $data)) {
                $snippet->setTags($data['tags']);
            }
            if (array_key_exists('category_id', $data)) {
                $snippet->setCategoryId($data['category_id']);
            }

            // Set the Privacy Status
            $status = new \Google_Service_YouTube_VideoStatus();
            $status->privacyStatus = $privacyStatus;

            // Set the Snippet & Status
            $video = new \Google_Service_YouTube_Video();
            $video->setSnippet($snippet);
            $video->setStatus($status);

            // Set the Chunk Size
            $chunkSize = 1 * 1024 * 1024;

            // Set the defer to true
            $this->client->setDefer(true);

            // Build the request
            $insert = $this->youtube->videos->insert('status,snippet', $video);

            // Upload
            $media = new \Google_Http_MediaFileUpload(
                $this->client,
                $insert,
                'video/*',
                null,
                true,
                $chunkSize
            );

            // Set the Filesize
            $media->setFileSize(filesize($path));

            // Read the file and upload in chunks
            $status = false;
            $handle = fopen($path, "rb");

            while (!$status && !feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                $status = $media->nextChunk($chunk);
            }

            fclose($handle);

            $this->client->setDefer(false);

            // Set ID of the Uploaded Video
            $this->videoId = $status['id'];

            // Set the Snippet from Uploaded Video
            $this->snippet = $status['snippet'];
        } catch (\Google_Service_Exception $e) {
            throw new Exception($e->getMessage());
        } catch (\Google_Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $this;
    }

    /**
     * Set a Custom Thumbnail for the Upload
     *
     * @param  string  $imagePath
     *
     * @return self
     */
    public function withThumbnail($imagePath)
    {
        try {
            $videoId = $this->getVideoId();

            $chunkSizeBytes = 1 * 1024 * 1024;

            $this->client->setDefer(true);

            $setRequest = $this->youtube->thumbnails->set($videoId);

            $media = new \Google_Http_MediaFileUpload(
                $this->client,
                $setRequest,
                'image/png',
                null,
                true,
                $chunkSizeBytes
            );
            $media->setFileSize(filesize($imagePath));

            $status = false;
            $handle = fopen($imagePath, "rb");

            while (!$status && !feof($handle)) {
                $chunk  = fread($handle, $chunkSizeBytes);
                $status = $media->nextChunk($chunk);
            }

            fclose($handle);

            $this->client->setDefer(false);
            $this->thumbnailUrl = $status['items'][0]['default']['url'];
        } catch (\Google_Service_Exception $e) {
            throw new Exception($e->getMessage());
        } catch (\Google_Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $this;
    }

    /**
     * Delete a YouTube video by it's ID.
     *
     * @param  int  $id
     *
     * @return bool
     */
    public function delete($id, $user_id = null)
    {
        $this->handleAccessToken($user_id);

        if (!$this->exists($id)) {
            throw new Exception('A video matching id "'. $id .'" could not be found.');
        }

        return $this->youtube->videos->delete($id);
    }

    /**
     * Check if a YouTube video exists by it's ID.
     *
     * @param  int  $id
     *
     * @return bool
     */
    public function exists($id, $user_id = null)
    {
        $this->handleAccessToken($user_id);

        $response = $this->youtube->videos->listVideos('status', ['id' => $id]);

        if (empty($response->items)) {
            return false;
        }

        return true;
    }

    /**
     * Return the Video ID
     *
     * @return string
     */
    public function getVideoId()
    {
        return $this->videoId;
    }

    /**
     * Return the snippet of the uploaded Video
     *
     * @return array
     */
    public function getSnippet()
    {
        return $this->snippet;
    }

    /**
     * Return the URL for the Custom Thumbnail
     *
     * @return string
     */
    public function getThumbnailUrl()
    {
        return $this->thumbnailUrl;
    }

    /**
     * Setup the Google Client
     *
     * @param \Google_Client $client
     * @return \Google_Client $client
     */
    private function setup(Google_Client $client)
    {
        if (!$this->app->config->get('youtube.client_id') ||
            !$this->app->config->get('youtube.client_secret')
        ) {
            throw new Exception('A Google "client_id" and "client_secret" must be configured.');
        }

        $client->setClientId($this->app->config->get('youtube.client_id'));
        $client->setClientSecret($this->app->config->get('youtube.client_secret'));
        $client->setScopes($this->app->config->get('youtube.scopes'));
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');
        $client->setRedirectUri(url(
            $this->app->config->get('youtube.routes.prefix')
            . '/' .
            $this->app->config->get('youtube.routes.redirect_uri')
        ));

        return $this->client = $client;
    }

    /**
     * Saves the access token to the database.
     *
     * @param  string  $accessToken
     */
    public function saveAccessTokenToDB($accessToken, $user_id = null)
    {
        return DB::table('youtube_access_tokens')->insert([
            'access_token' => json_encode($accessToken),
            'created_at'   => Carbon::createFromTimestamp($accessToken['created']),
            'user_id'      => $user_id ?? $this->app->auth->user()->id,
        ]);
    }

    /**
     * Get the latest access token from the database.
     *
     * @return string
     */
    public function getLatestAccessTokenFromDB($user_id = null)
    {
        $latest = DB::table('youtube_access_tokens')
                    ->where('user_id', $user_id ?? $this->app->auth->user()->id)
                    ->latest('created_at')
                    ->first();

        return $latest ? (is_array($latest) ? $latest['access_token'] : $latest->access_token ) : null;
    }

    /**
     * Does user have a Access Token
     *
     * @return boolean $true
     */
    public function hasAccessToken($user_id = null)
    {
        return $this->getLatestAccessTokenFromDB($user_id) ? true : false;
    }

    /**
     * Does user have a Refresh Token
     *
     * @return boolean $true
     */
    public function hasRefreshToken($user_id = null)
    {
        if ($accessToken = $this->getLatestAccessTokenFromDB($user_id)) {
            $this->client->setAccessToken($accessToken);
        }
        if (is_null($accessToken = $this->client->getAccessToken())) {
            return false;
        }

        $accessToken = json_decode($accessToken);

        // If we have a "refresh_token"
        if (property_exists($accessToken, 'refresh_token')) {
            return true;
        }
        return false;
    }

    /**
     * Is access token expired
     *
     * @return boolean $true
     */
    public function isAccessTokenExpired($user_id = null)
    {
        if ($accessToken = $this->getLatestAccessTokenFromDB($user_id)) {
            $this->client->setAccessToken($accessToken);
        }
        if (is_null($accessToken = $this->client->getAccessToken())) {
            throw new \Exception('An access token is required.');
        }

        if ($this->client->isAccessTokenExpired()) {
            return true;
        }
        return false;
    }


    /**
     * Handle the Access Token
     *
     * @return void
     */
    public function handleAccessToken($user_id = null)
    {
        if ($accessToken = $this->getLatestAccessTokenFromDB($user_id)) {
            $this->client->setAccessToken($accessToken);
        }

        if (is_null($accessToken = $this->client->getAccessToken())) {
            throw new \Exception('An access token is required.');
        }

        if ($this->client->isAccessTokenExpired()) {
            $accessToken = json_decode($accessToken);

            // If we have a "refresh_token"
            if (property_exists($accessToken, 'refresh_token')) {
                // Refresh the access token
                $this->client->refreshToken($accessToken->refresh_token);

                // Save the access token
                $this->saveAccessTokenToDB($this->client->getAccessToken(), $user_id);
            }
        }
    }

    /**
     * Pass method calls to the Google Client.
     *
     * @param  string  $method
     * @param  array   $args
     *
     * @return mixed
     */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->client, $method], $args);
    }
}
