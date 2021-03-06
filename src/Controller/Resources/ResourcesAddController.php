<?php
/**
 * Passbolt ~ Open source password manager for teams
 * Copyright (c) Passbolt SA (https://www.passbolt.com)
 *
 * Licensed under GNU Affero General Public License version 3 of the or any later version.
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Passbolt SA (https://www.passbolt.com)
 * @license       https://opensource.org/licenses/AGPL-3.0 AGPL License
 * @link          https://www.passbolt.com Passbolt(tm)
 * @since         2.0.0
 */

namespace App\Controller\Resources;

use App\Controller\AppController;
use App\Error\Exception\ValidationException;
use App\Model\Entity\Permission;
use App\Model\Table\ResourcesTable;
use Cake\Event\Event;

/**
 * @property ResourcesTable Resources
 */
class ResourcesAddController extends AppController
{
    const ADD_SUCCESS_EVENT_NAME = 'ResourcesAddController.addPost.success';

    /**
     * Resource Add action
     *
     * @return void
     * @throws \Exception
     */
    public function add()
    {
        $this->loadModel('Resources');

        $data = $this->_formatRequestData();
        $resource = $this->_buildAndValidateEntity($data);

        $result = $this->Resources->getConnection()->transactional(function () use ($resource, $data) {
            $result = $this->Resources->save($resource, ['atomic' => false]);
            $this->_handleValidationError($resource);
            $this->afterCreate($resource, $data);
            $this->_handleValidationError($resource);

            return $result;
        });

        // Retrieve the saved resource.
        $options = [
            'contain' => [
                'creator' => true, 'favorite' => true, 'modifier' => true,
                'secret' => true, 'permission' => true,
            ],
        ];

        $resource = $this->Resources->findView($this->User->id(), $result->id, $options)->first();

        $this->success(__('The resource has been added successfully.'), $resource);
    }

    /**
     * Build the resource entity from user input
     *
     * @param array $data Array of data
     * @return \App\Model\Entity\Resource
     */
    protected function _buildAndValidateEntity(array $data)
    {
        // Enforce data.
        $data['created_by'] = $this->User->id();
        $data['modified_by'] = $this->User->id();
        $data['permissions'] = [[
            'aro' => 'User',
            'aro_foreign_key' => $this->User->id(),
            'aco' => 'Resource',
            'type' => Permission::OWNER,
        ]];
        // If no secrets given, the model will throw a validation error, no need to take care of it here.
        if (isset($data['secrets'])) {
            $data['secrets'][0]['user_id'] = $this->User->id();
        }

        // Build entity and perform basic check
        $resource = $this->Resources->newEntity($data, [
            'accessibleFields' => [
                'name' => true,
                'username' => true,
                'uri' => true,
                'description' => true,
                'created_by' => true,
                'modified_by' => true,
                'secrets' => true,
                'permissions' => true,
            ],
            'associated' => [
                'Permissions' => [
                    'validate' => 'saveResource',
                    'accessibleFields' => [
                        'aco' => true,
                        'aro' => true,
                        'aro_foreign_key' => true,
                        'type' => true,
                    ],
                ],
                'Secrets' => [
                    'validate' => 'saveResource',
                    'accessibleFields' => [
                        'user_id' => true,
                        'data' => true,
                    ],
                ],
            ],
        ]);

        // Handle validation errors if any at this stage.
        $this->_handleValidationError($resource);

        return $resource;
    }

    /**
     * Format request data formatted for API v1 to API v2 format
     *
     * @return array
     */
    protected function _formatRequestData()
    {
        $output = [];
        $data = $this->request->getData();

        // API v2 additional checks and error (was silent before)
        if ($this->getApiVersion() == 'v2') {
            $output = $data;
        } else {
            if (isset($data['Resource'])) {
                $output = $data['Resource'];
            }
            if (isset($data['Secret'])) {
                $output['secrets'] = $data['Secret'];
            }
        }

        return $output;
    }

    /**
     * Manage validation errors.
     *
     * @param \App\Model\Entity\Resource $resource the
     * @throws ValidationException if the resource validation failed
     * @return void
     */
    protected function _handleValidationError(\App\Model\Entity\Resource $resource)
    {
        $errors = $resource->getErrors();
        if (!empty($errors)) {
            throw new ValidationException(__('Could not validate resource data.'), $resource, $this->Resources);
        }
    }

    /**
     * Trigger the after resource create event.
     * @param \App\Model\Entity\Resource $resource The created resource
     * @param array $data The request data.
     * @return void
     */
    protected function afterCreate(\App\Model\Entity\Resource $resource, array $data = [])
    {
        $uac = $this->User->getAccessControl();
        $eventData = ['resource' => $resource, 'accessControl' => $uac, 'data' => $data];
        $event = new Event(static::ADD_SUCCESS_EVENT_NAME, $this, $eventData);
        $this->getEventManager()->dispatch($event);
    }
}
