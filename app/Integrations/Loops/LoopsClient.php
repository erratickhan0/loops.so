<?php

class LoopsClient
{
    private $api_key;
    private $api_endpoint = 'https://app.loops.so/api/v1';
    public $verify_ssl = true;


    /**
     * Create a new instance and set up the API key
     */
    public function __construct()
    {
        // Fetch the API key from the services.php config file
        $this->api_key = config('services.loops.api_key'); //pickup this path from config/services.php

        if (!$this->api_key) {
            throw new Exception("API key for Loops is not set in the config file.");
        }
    }

    /**
     * Make an HTTP POST request - for creating data
     *
     * @param string $method URL of the API request method
     * @param array  $args   Associative array of data to be sent
     * @param int    $timeout Timeout limit for request in seconds
     * @param string $alt_endpoint Optional alternative endpoint for requests
     * @return array|false Associative array of API response, decoded from JSON
     */
    public function create($method, $args = [], $timeout = 30, $alt_endpoint = '')
    {
        return $this->makeRequest('post', $method, $args, $timeout, $alt_endpoint);
    }

    /**
     * Make an HTTP PUT request - for updating data
     *
     * @param string $method URL of the API request method
     * @param array  $args   Associative array of data to be sent
     * @param int    $timeout Timeout limit for request in seconds
     * @param string $alt_endpoint Optional alternative endpoint for requests
     * @return array|false Associative array of API response, decoded from JSON
     */
    public function update($method, $args = [], $timeout = 30, $alt_endpoint = '')
    {
        return $this->makeRequest('put', $method, $args, $timeout, $alt_endpoint);
    }

    /**
     * Make an HTTP DELETE request - for deleting data
     *
     * @param string $method URL of the API request method
     * @param array  $args   Associative array of data to be sent
     * @param int    $timeout Timeout limit for request in seconds
     * @param string $alt_endpoint Optional alternative endpoint for requests
     * @return array|false Associative array of API response, decoded from JSON
     */
    public function delete($method, $args = [], $timeout = 30, $alt_endpoint = '')
    {
        return $this->makeRequest('delete', $method, $args, $timeout, $alt_endpoint);
    }
    /**
     * Make an HTTP GET request - for retrieving data
     *
     * @param string $method URL of the API request method
     * @param array  $args   Associative array of data to be sent as query parameters
     * @param int    $timeout Timeout limit for request in seconds
     * @return array|false Associative array of API response, decoded from JSON
     */
    public function get($method, $args = [], $timeout = 30)
    {
        return $this->makeRequest('get', $method, $args, $timeout);
    }

    /**
     * Perform the underlying HTTP request
     *
     * @param string $http_verb The HTTP verb to use: post, put, delete
     * @param string $method The API method to be called
     * @param array  $args Associative array of parameters to be passed
     * @param int    $timeout Timeout limit for request in seconds
     * @param string $alt_endpoint Optional alternative endpoint for requests
     * @return array|false Associative array of decoded result
     */
    private function makeRequest($http_verb, $method, $args = [], $timeout = 30, $alt_endpoint = '')
    {
        if (!function_exists('curl_init') || !function_exists('curl_setopt')) {
            throw new Exception("cURL support is required, but can't be found.");
        }

        // Construct the URL for the API
        $url = $this->getUrl($method, $alt_endpoint);

        // Initialize cURL session
        $ch = curl_init();

        // Check for POST request and send the args as JSON body
        if ($http_verb === 'post' || $http_verb === 'put' || $http_verb === 'delete' ) {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($args)); // Send args as JSON
        } else {
            // For non-POST requests, use query parameters (GET)
            $query = http_build_query($args);
            curl_setopt($ch, CURLOPT_URL, $url . '?' . $query);
        }

        // Set necessary headers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',  // Ensure JSON Content-Type
            'Authorization: Bearer ' . $this->api_key,      // Authorization with API key
        ]);

        // Other cURL settings
        curl_setopt($ch, CURLOPT_USERAGENT, 'Generic/LoopsClientConnector');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verify_ssl);

        // Execute the cURL request
        $response = curl_exec($ch);
        // Check if the request was successful
        if ($response === false) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }

        // Get HTTP status code
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Close cURL session
        curl_close($ch);

        // Only pass the body string to formatResponse
        return $this->formatResponse($response);
    }




    /**
     * Construct the URL for the API request
     *
     * @param string $method API method to be called
     * @param string $alt_endpoint Optional alternative endpoint for requests
     * @return string Full URL for the API request
     */
    private function getUrl($method, $alt_endpoint)
    {
        if (strlen($alt_endpoint)) {
            return $alt_endpoint . '/' . $method;
        }
        return $this->api_endpoint . '/' . $method;
    }

    /**
     * Decode the response and format any error messages for debugging
     *
     * @param string $body Response body
     * @return array|false Decoded response array, or false in case of error
     */
    private function formatResponse($body)
    {
        if (!empty($body)) {
            $d = json_decode($body, true);

            // Check if response is an array with contacts
            if (is_array($d) && count($d) > 0) {
                return $d;  // Return contact data if found
            }
        }

        return false; // Return false if no contact found
    }

}