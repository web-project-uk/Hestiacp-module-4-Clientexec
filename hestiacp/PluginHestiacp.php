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
                'value' => '0',
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
        $this->suspend($args);
        return $userPackage->getCustomField("Domain Name") . ' has been suspended.';
    }

    public function suspend($args)
    {
        try {
            if (!$this->checkDomainExists($args, $args['package']['username'], $args['package']['domain_name'])) {
                throw new CE_Exception('Domain does not exist');
            }
            
            $this->makeCommand($args, 'v-suspend-web-domain', [
                $args['package']['username'],
                $args['package']['domain_name']
            ]);
        } catch (Exception $e) {
            throw new CE_Exception('Failed to suspend domain: ' . $e->getMessage());
        }
    }

    public function doUnSuspend($args)
    {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->unsuspend($args);
        return $userPackage->getCustomField("Domain Name") . ' has been unsuspended.';
    }

    public function unsuspend($args)
    {
        try {
            if (!$this->checkDomainExists($args, $args['package']['username'], $args['package']['domain_name'])) {
                throw new CE_Exception('Domain does not exist');
            }

            $this->makeCommand($args, 'v-unsuspend-web-domain', [
                $args['package']['username'],
                $args['package']['domain_name']
            ]);
        } catch (Exception $e) {
            throw new CE_Exception('Failed to unsuspend domain: ' . $e->getMessage());
        }
    }

    public function getAvailableActions($userPackage)
    {
        $args = $this->buildParams($userPackage);
        $actions = [];

        try {
            if (empty($args['package']['username'])) {
                return ['Create'];
            }

            $userExists = $this->checkUserExists($args, $args['package']['username']);
            if (!$userExists) {
                return ['Create'];
            }

            $domainExists = $this->checkDomainExists($args, $args['package']['username'], $args['package']['domain_name']);
            if (!$domainExists) {
                return ['Create'];
            }

            $actions[] = 'Delete';

            // Check suspension status
            $response = $this->makeCommand($args, 'v-list-web-domain', [
                $args['package']['username'],
                $args['package']['domain_name'],
                'json'
            ]);

            $domainInfo = json_decode($response, true);
            if ($domainInfo && isset($domainInfo[$args['package']['domain_name']]['SUSPENDED'])) {
                $actions[] = ($domainInfo[$args['package']['domain_name']]['SUSPENDED'] === 'yes') ? 'UnSuspend' : 'Suspend';
            } else {
                $actions[] = 'Suspend';
            }

        } catch (Exception $e) {
            return ['Create'];
        }

        return $actions;
    }

    private function checkUserExists($args, $username)
    {
        try {
            $this->makeCommand($args, 'v-list-user', [$username]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function checkDomainExists($args, $username, $domain)
    {
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