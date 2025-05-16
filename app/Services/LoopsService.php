<?php

namespace App\Services;

use App\Integrations\Loops\LoopsClient;
use App\Jobs\SyncUserToLoops;
use App\Models\Agency;
use App\Models\User;

class LoopsService
{
    protected $loopsClient;

    // Injecting LoopsClient in the constructor
    public function __construct(LoopsClient $loopsClient)
    {
        $this->loopsClient = $loopsClient;
    }

    /**
     * Create a new user.
     *
     * @param User $user
     * @return void
     */
    public function createUser(User $user)
    {
        try {
            // Make API call to create user
            $existingContact = $this->findContactByEmail($user->email);
            if(!$existingContact){
                $contactData = $this->prepareUserData($user);
                $response = $this->loopsClient->create('contacts/create', $contactData);
                $this->LogResponse($response,$user->email);
            }

        } catch (\Exception $e) {
            app('sentry')->captureException($e);
        }
    }

    /**
     * Update an existing user.
     *
     * @param User $user
     * @param array $changedFields
     * @return void
     */
    public function updateUser(User $user, array $changedFields)
    {
        try {
            // First, check if the contact exists
            $existingContact = $this->findContactByEmail($user->email);

            if(empty($existingContact)){
                $contactData = $this->prepareUserData($user);
                $response = $this->loopsClient->create('contacts/create', $contactData);
                $this->LogResponse($response, $user->email);

                return;
            }
            // Prepare user data and include email explicitly only if contact doesn't exist
            $contactData = $this->prepareUserData($user, $changedFields);
            $contactData['email'] = $user->email;

            $response = $this->loopsClient->update('contacts/update', $contactData);
            $this->LogResponse($response, $user->email);

        } catch (\Exception $e) {
            app('sentry')->captureException($e);
        }
    }

    /**
     * Remove an existing user from Loops when active_state is false.
     *
     * @param User $user
     * @return void
     */
    public function removeUser(User $user)
    {
        try {
            $response = $this->loopsClient->delete('contacts/delete', ['email' => $user->email]);
            $this->LogResponse($response, $user->email);

        } catch (\Exception $e) {
            app('sentry')->captureException($e);
        }
    }

    /**
     * Handle user update logic based on changed fields.
     *
     * @param User $user
     * @param array $changedFields
     * @return void
     */
    public function handleUserUpdate(User $user, array $changedFields)
    {
        // Handle active_state change separately
        if (array_key_exists('active_state', $changedFields)) {
            if ($user->active_state == 0) {
                $this->removeUser($user);
            } else {
                $this->createUser($user);
            }
        } else {
            // Update user for other field changes
            $this->updateUser($user, $changedFields);
        }
    }

    /**
     * Prepare user data for Loops API request.
     *
     * @param User $user
     * @param array|null $changedFields
     * @return array
     */
    /**
     * Prepare user data for Loops API request.
     *
     * @param User $user
     * @param array|null $changedFields
     * @return array
     */
    private function prepareUserData(User $user, array $changedFields = null)
    {
        $agency = $user->agency; // Assuming user has an 'agency' relationship
        // Define the fields we need to check for changes
        $userFields = [
            'first_name' => 'firstName',
            'last_name' => 'lastName',
        ];

        $agencyFields = [
            'is_agency' => 'Agency',
            'is_publisher' => 'Publisher',
            'is_reseller' => 'Reseller',
            'is_buyer' => 'Brand',
        ];

        $data = [
            'email' => $user->email,
            'userGroup' => 'SearchEye',
            'source' => 'API',
            'userId' => $user->id,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'Brand' => $agency ? (bool) $agency->is_buyer : false,
            'Agency' => $agency ? (bool) $agency->is_agency : false,
            'Reseller' => $agency ? (bool) $agency->is_reseller : false,
            'Publisher' => $agency ? (bool) $agency->is_publisher : false,
        ];

        // If specific fields were changed, only include those fields
        if ($changedFields) {
            $changedData = [];

            // Check and update user fields
            foreach ($userFields as $field => $fieldKey) {
                if (in_array($field, array_keys($changedFields))) {
                    $changedData[$fieldKey] = $user->$field;
                }
            }

            // Check and update agency fields
            foreach ($agencyFields as $field => $fieldKey) {
                if (in_array($field, array_keys($changedFields))) {
                    $changedData[$fieldKey] = $agency ? (bool) $agency->$field : false;
                }
            }
            return $changedData;
        }

        return $data;
    }


    /**
     * Log the response and capture message in Sentry if success is false.
     *
     * @param array $response
     * @param string|null $email
     * @return void
     */
    private function LogResponse($response, string $email = null)
    {
        if (isset($response['success']) && !$response['success']) {
            $errorMessage = $response['message'] ?? 'Unknown error occurred';
            $logMessage = $email ? "$errorMessage (User Email: $email)" : $errorMessage;

            app('sentry')->captureMessage($logMessage);
        }
    }


    /**
     * Find a contact by email and return its ID.
     *
     * @param string $email The email of the contact to be fetched
     * @return int|false The contact ID or false if not found
     */
    public function findContactByEmail($email)
    {
        $response = $this->loopsClient->get('contacts/find', ['email' => $email]);

        // Check if response is not empty and is an array, then check if the first element exists
        if (!empty($response) && is_array($response) && isset($response[0]['id'])) {
            return $response[0]['id'];
        }

        return false;
    }


    /**
     * Handle agency state changes and synchronize users in Loops.
     *
     * @param Agency $agency
     * @param array $changedAttributes
     */

    public function handleAgencyStateChange(Agency $agency, array $changedAttributes)
    {
        $isActiveStateChange = array_key_exists('active_state', $changedAttributes);
        $isAgencyActive = $agency->active_state == 1;

        foreach ($agency->users as $user) {
            if ($isActiveStateChange) {
                if (!$isAgencyActive) {
                    // Dispatch job to remove the user
                    SyncUserToLoops::dispatch($user, 'remove');
                } elseif ($user->active_state == 1) {
                    // Dispatch job to create the user
                    SyncUserToLoops::dispatch($user, 'create');
                }
            } else {
                // Dispatch job to update the user
                SyncUserToLoops::dispatch($user, 'update', $changedAttributes);
            }
        }
    }




}
