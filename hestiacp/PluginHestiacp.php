<?php

require_once 'modules/admin/models/ServerPlugin.php';

class PluginHestiacp extends ServerPlugin
{
    public $features = [
        'packageName' => true,
        'testConnection' => true,
        'showNameservers' => true,
        'directlink' => true
    ];

    public function getVariables()
    {
        $variables = [
            'Name' => [
                'type' => 'hidden',
                'description' => 'Used by CE to show plugin',
                'value' => 'Hestia Control Panel'
            ],
            'Description' => [
                'type' => 'hidden',
                'description' => 'Description viewable by admin in server settings',
                'value' => lang('Hestia Control Panel Plugin')
            ],
            'Username' => [
                'type' => 'text',
                'description' => lang('Admin Username'),
                'value' => '',
                'encryptable' => true
            ],
            'Password' => [
                'type' => 'password',
                'description' => lang('Admin Password'),
                'value' => '',
                'encryptable' => true
            ],
            'PortNumber' => [
                'type' => 'text',
                'description' => lang('Control Panel Port'),
                'value' => '8083'
            ],
            'Actions' => [
                'type' => 'hidden',
                'description' => 'Current actions that are active for this plugin per server',
                'value' => 'Create,Delete,Suspend,UnSuspend'
            ],
            'Registered Actions For Customer' => [
                'type' => 'hidden',
                'description' => 'Current actions that are active for this plugin per server for customers',
                'value' => ''
            ],
            'package_addons' => [
                'type' => 'hidden',
                'description' => 'Supported signup addons variables',
                'value' => []
            ],
            'package_vars' => [
                'type' => 'hidden',
                'description' => 'Whether package settings are set',
                'value' => '1',
            ]
        ];
        return $variables;
    }

    public function testConnection($args)
    {
        try {
            $response = $this->makeCommand($args, 'v-list-user', [$args['server']['variables']['plugin_hestiacp_Username']]);
            return 'Connection successful';
        } catch (Exception $e) {
            throw new CE_Exception('Connection test failed: ' . $e->getMessage());
        }
    }

    public function doCreate($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->create($args);
        return $userPackage->getCustomField("Domain Name") . ' has been created.';
    }

    public function create($args)
    {
        try {
            // Check if user already exists
            $userExists = $this->checkUserExists($args, $args['package']['username']);
            if ($userExists) {
                throw new CE_Exception('User already exists');
            }

            // Create user
            $response = $this->makeCommand($args, 'v-add-user', [
                $args['package']['username'],
                $args['package']['password'],
                $args['customer']['email'],
                $args['package']['name_on_server'],
                $args['package']['username']
            ]);

            // Check if domain exists
            $domainExists = $this->checkDomainExists($args, $args['package']['username'], $args['package']['domain_name']);
            if ($domainExists) {
                // Cleanup user if domain exists
                $this->makeCommand($args, 'v-delete-user', [$args['package']['username']]);
                throw new CE_Exception('Domain already exists');
            }

            // Create domain
            $response = $this->makeCommand($args, 'v-add-web-domain', [
                $args['package']['username'],
                $args['package']['domain_name']
            ]);

        } catch (Exception $e) {
            // Cleanup on any error
            try {
                if ($this->checkUserExists($args, $args['package']['username'])) {
                    $this->makeCommand($args, 'v-delete-user', [$args['package']['username']]);
                }
            } catch (Exception $cleanupError) {
                // Log cleanup error but throw original error
            }
            throw new CE_Exception('Failed to create account: ' . $e->getMessage());
        }
    }

    public function doDelete($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->delete($args);
        return $userPackage->getCustomField("Domain Name") . ' has been deleted.';
    }

    public function delete($args)
    {
        try {
            // Check if domain exists before attempting delete
            if ($this->checkDomainExists($args, $args['package']['username'], $args['package']['domain_name'])) {
                $this->makeCommand($args, 'v-delete-web-domain', [
                    $args['package']['username'],
                    $args['package']['domain_name']
                ]);
            }

            // Check if user exists before attempting delete
            if ($this->checkUserExists($args, $args['package']['username'])) {
                $this->makeCommand($args, 'v-delete-user', [
                    $args['package']['username']
                ]);
            }
        } catch (Exception $e) {
            throw new CE_Exception('Failed to delete account: ' . $e->getMessage());
        }
    }
	
	public function doSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $result = $this->suspend($args);
        if ($result) {
            $userPackage->setCustomField('Suspended', 'Yes');
        }
        return $userPackage->getCustomField("Domain Name") . ' has been suspended.';
    }

    public function suspend($args) {
    try {
        $username = $args['package']['username'];

        if (!$this->checkUserExists($args, $username)) {
            throw new CE_Exception('User does not exist');
        }

        $result = $this->makeCommand($args, 'v-suspend-user', [$username]);
        if ($result !== '0') {
            throw new CE_Exception('Suspension failed: ' . $result);
        }

        return true;
    } catch (Exception $e) {
        throw new CE_Exception('Suspension failed: ' . $e->getMessage());
    }
}

    public function doUnSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $result = $this->unsuspend($args);
        if ($result) {
            $userPackage->setCustomField('Suspended', 'No');
        }
        return $userPackage->getCustomField("Domain Name") . ' has been unsuspended.';
    }

    public function unsuspend($args) {
    try {
        $username = $args['package']['username'];

        if (!$this->checkUserExists($args, $username)) {
            throw new CE_Exception('User does not exist');
        }

        $result = $this->makeCommand($args, 'v-unsuspend-user', [$username]);
        if ($result !== '0') {
            throw new CE_Exception('Unsuspension failed: ' . $result);
        }

        return true;
    } catch (Exception $e) {
        throw new CE_Exception('Unsuspension failed: ' . $e->getMessage());
    }
}

    private function checkSuspensionStatus($args)
    {
        $host = $args['server']['variables']['ServerHostName'];
        $adminUsername = $args['server']['variables']['plugin_hestiacp_Username'];
        $adminPassword = $args['server']['variables']['plugin_hestiacp_Password'];
        $port = $args['server']['variables']['plugin_hestiacp_PortNumber'];
        $username = $args['package']['username'];

        // Log the request details (excluding password)
        CE_Lib::log(4, "HestiaCP Suspension Check - Host: $host, Admin: $adminUsername, User: $username, Port: $port");

        $postData = [
            'user' => $adminUsername,
            'password' => $adminPassword,
            'cmd' => 'v-list-user',
            'arg1' => $username,
            'arg2' => 'json'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://{$host}:{$port}/api/",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_VERBOSE => true
        ]);

        // Get curl debug information
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        // Get verbose debug information
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        fclose($verbose);

        // Log the curl details
        CE_Lib::log(4, "HestiaCP Curl Debug Info: " . $verboseLog);
        CE_Lib::log(4, "HestiaCP HTTP Code: " . $httpCode);
        CE_Lib::log(4, "HestiaCP Raw Response: " . $response);

        if ($curlError) {
            CE_Lib::log(4, "HestiaCP Curl Error: " . $curlError);
            throw new CE_Exception("Connection error: $curlError");
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            throw new CE_Exception("HTTP Error $httpCode");
        }

        return $response;
    }

   public function getAvailableActions($userPackage)
    {
        $args = $this->buildParams($userPackage);
        $actions = [];
        
        try {
            CE_Lib::log(4, "HestiaCP getAvailableActions - Starting for username: " . $args['package']['username']);

            if (empty($args['package']['username'])) {
                CE_Lib::log(4, "HestiaCP getAvailableActions - Empty username, returning Create only");
                return ['Create'];
            }
            
            $userExists = $this->checkUserExists($args, $args['package']['username']);
            if (!$userExists) {
                CE_Lib::log(4, "HestiaCP getAvailableActions - User does not exist, returning Create only");
                return ['Create'];
            }

            $domainExists = $this->checkDomainExists($args, $args['package']['username'], $args['package']['domain_name']);
            if (!$domainExists) {
                CE_Lib::log(4, "HestiaCP getAvailableActions - Domain does not exist, returning Create only");
                return ['Create'];
            }

            // Add Delete action since user and domain exist
            $actions[] = 'Delete';

            try {
                // Get suspension status using direct curl request
                $response = $this->checkSuspensionStatus($args);
                CE_Lib::log(4, "HestiaCP Direct API Response: " . $response);
                
                $userInfo = json_decode($response, true);
                if ($userInfo === null) {
                    throw new CE_Exception("Invalid JSON response");
                }

                // Log the decoded structure
                CE_Lib::log(4, "HestiaCP Decoded Response Structure: " . print_r($userInfo, true));

                $isSuspended = false;
                $suspensionFound = false;

                // Try all possible response structures
                if (isset($userInfo[$args['package']['username']]['SUSPENDED'])) {
                    $isSuspended = strtolower($userInfo[$args['package']['username']]['SUSPENDED']) === 'yes';
                    $suspensionFound = true;
                    CE_Lib::log(4, "Found suspension status in username index");
                } elseif (isset($userInfo['SUSPENDED'])) {
                    $isSuspended = strtolower($userInfo['SUSPENDED']) === 'yes';
                    $suspensionFound = true;
                    CE_Lib::log(4, "Found suspension status in root");
                } elseif (is_array($userInfo) && count($userInfo) > 0) {
                    $firstElement = reset($userInfo);
                    if (is_array($firstElement) && isset($firstElement['SUSPENDED'])) {
                        $isSuspended = strtolower($firstElement['SUSPENDED']) === 'yes';
                        $suspensionFound = true;
                        CE_Lib::log(4, "Found suspension status in first element");
                    }
                }

                if ($suspensionFound) {
                    if ($isSuspended) {
                        $actions[] = 'UnSuspend';
                        CE_Lib::log(4, "Account is suspended - Adding UnSuspend action");
                    } else {
                        $actions[] = 'Suspend';
                        CE_Lib::log(4, "Account is not suspended - Adding Suspend action");
                    }
                } else {
                    CE_Lib::log(4, "Could not determine suspension status - Defaulting to Suspend action");
                    $actions[] = 'Suspend';
                }

            } catch (Exception $e) {
                CE_Lib::log(4, "Error checking suspension status: " . $e->getMessage());
                $actions[] = 'Suspend';
            }
            
        } catch (Exception $e) {
            CE_Lib::log(4, "Error in getAvailableActions: " . $e->getMessage());
            return ['Create'];
        }

        // Clean and validate the actions array
        $validActions = ['Create', 'Delete', 'Suspend', 'UnSuspend'];
        $actions = array_values(array_unique(array_filter($actions, function($action) use ($validActions) {
            return in_array($action, $validActions);
        })));

        CE_Lib::log(4, "Final Available Actions: " . print_r($actions, true));
        return $actions;
    }

	public function doUpdate($args)
{
    $userPackage = new UserPackage($args['userPackageId']);
    $args = $this->buildParams($userPackage);
    $this->update($args);
    return $userPackage->getCustomField("Domain Name") . ' has been updated.';
}

public function update($args)
{
    try {
        $username = $args['package']['username'];
        
        // Log the update attempt and full args for debugging
        CE_Lib::log(4, "HestiaCP Update - Starting update for user: $username");
        CE_Lib::log(4, "HestiaCP Update - Full args: " . print_r($args, true));
        
        // Check if user exists before attempting updates
        if (!$this->checkUserExists($args, $username)) {
            CE_Lib::log(4, "HestiaCP Update - User does not exist: $username");
            throw new CE_Exception("Cannot update: User {$username} does not exist");
        }

        foreach ($args['changes'] as $key => $value) {
            switch ($key) {
                case 'password':
                    try {
                        CE_Lib::log(4, "HestiaCP Update - Attempting password update for user: $username");
                        $response = $this->makeCommand($args, 'v-change-user-password', [
                            $username,
                            $value
                        ]);
                        
                        if ($response !== '0') {
                            CE_Lib::log(4, "HestiaCP Update - Password update failed with response: $response");
                            throw new CE_Exception("Failed to update password. Response: $response");
                        }
                        
                        CE_Lib::log(4, "HestiaCP Update - Successfully updated password for user: $username");
                    } catch (Exception $e) {
                        CE_Lib::log(4, "HestiaCP Update - Password update exception: " . $e->getMessage());
                        throw new CE_Exception("Password update failed: " . $e->getMessage());
                    }
                    break;

                case 'package':
                    try {
                        // Get the new package details
                        $newPackage = new Package($value);
                        $packageName = $args['name_on_server'];  // Changed from getName() to getNameOnServer()
                        
                        CE_Lib::log(4, "HestiaCP Update - Package object details: " . print_r($newPackage, true));
                        CE_Lib::log(4, "HestiaCP Update - Attempting package update for user: $username to package: $packageName");
                        
                        // Verify we have a package name
                        if (empty($packageName)) {
                            CE_Lib::log(4, "HestiaCP Update - Empty package name received");
                            throw new CE_Exception("Cannot update to empty package name");
                        }

                        // Log the API call details
                        CE_Lib::log(4, "HestiaCP Update - Making API call v-change-user-package for user: $username with package: $packageName");
                        
                        // Update to new package name using the correct API command
                        $response = $this->makeCommand($args, 'v-change-user-package', [
                            $username,
                            $packageName
                        ]);
                        
                        // Log the raw response for debugging
                        CE_Lib::log(4, "HestiaCP Update - Raw API response: " . print_r($response, true));
                        
                        if ($response !== '0') {
                            CE_Lib::log(4, "HestiaCP Update - Package update failed with response: $response");
                            throw new CE_Exception("Failed to update package. Response: $response");
                        }
                        
                        CE_Lib::log(4, "HestiaCP Update - Successfully updated package for user: $username to: $packageName");
                    } catch (Exception $e) {
                        CE_Lib::log(4, "HestiaCP Update - Package update exception: " . $e->getMessage());
                        throw new CE_Exception("Package update failed: " . $e->getMessage());
                    }
                    break;
            }
        }
        
        CE_Lib::log(4, "HestiaCP Update - Successfully completed all updates for user: $username");
        return true;
    } catch (Exception $e) {
        CE_Lib::log(4, "HestiaCP Update - Critical error: " . $e->getMessage());
        throw new CE_Exception("Update failed: " . $e->getMessage());
    }
}
	
	public function getDirectLink($userPackage, $getRealLink = true, $fromAdmin = false, $isReseller = false)
    {
        $linkText = $this->user->lang('Login to Server');
        $args = $this->buildParams($userPackage);

        if ($getRealLink) {
            // call login at server

            return [
                'link'    => '<li><a target="_blank" href="https://{$host}:{$port}/">' . $linkText . '</a></li>',
                'rawlink' =>  'url to login',
                'form'    => ''
            ];
        } else {
            return [
                'link' => '<li><a target="_blank" href="index.php?fuse=clients&controller=products&action=openpackagedirectlink&packageId=' . $userPackage->getId() . '&sessionHash=' . CE_Lib::getSessionHash() . '">' . $linkText . '</a></li>',
                'form' => ''
            ];
        }
    }

    public function dopanellogin($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $response = $this->getDirectLink($userPackage);
        return $response['rawlink'];
    }


 
    // Existing helper methods remain the same as in the original code
    private function checkUserExists($args, $username) {
        try {
            $this->makeCommand($args, 'v-list-user', [$username]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function checkDomainExists($args, $username, $domain) {
        try {
            $this->makeCommand($args, 'v-list-web-domain', [$username, $domain]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function makeCommand($args, $cmd, $cmdArgs = [])
    {
        $host = $args['server']['variables']['ServerHostName'];
        $username = $args['server']['variables']['plugin_hestiacp_Username'];
        $password = $args['server']['variables']['plugin_hestiacp_Password'];
        $port = $args['server']['variables']['plugin_hestiacp_PortNumber'];

        if (empty($host) || empty($username) || empty($password) || empty($port)) {
            throw new CE_Exception('Missing required connection details');
        }

        $postData = [
            'user' => $username,
            'password' => $password,
            'returncode' => 'yes',
            'cmd' => $cmd
        ];

        // Add command arguments
        foreach ($cmdArgs as $index => $arg) {
            if ($arg !== '') {  // Only add non-empty arguments
                $postData['arg' . ($index + 1)] = $arg;
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://{$host}:{$port}/api/",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new CE_Exception("Connection error: $curlError");
        }

        if ($httpCode !== 200) {
            throw new CE_Exception("HTTP Error $httpCode");
        }

        $response = trim($response);
        
        // For list commands with JSON output
        if (in_array('json', $cmdArgs)) {
            if (json_decode($response) === null) {
                throw new CE_Exception('Invalid JSON response from API');
            }
            return $response;
        }

        // For all other commands, check return code
        if ($response !== '0') {
            throw new CE_Exception("API Error: $response");
        }

        return $response;
    }
}