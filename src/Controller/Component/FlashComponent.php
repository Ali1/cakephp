<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Controller\Component;

use Cake\Controller\Component;
use Cake\Event\EventInterface;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\Session;
use Cake\Utility\Inflector;
use Exception;
use UnexpectedValueException;

/**
 * The CakePHP FlashComponent provides a way for you to write a flash variable
 * to the session from your controllers, to be rendered in a view with the
 * FlashHelper.
 *
 * @method void success(string $message, array $options = []) Set a message using "success" element
 * @method void error(string $message, array $options = []) Set a message using "error" element
 */
class FlashComponent extends Component
{
    /**
     * Default configuration
     *
     * @var array
     */
    protected $_defaultConfig = [
        'key' => 'flash',
        'element' => 'default',
        'type' => 'default',
        'params' => [],
        'clear' => false,
        'duplicate' => true,
    ];

    /**
     * Called after the Controller::beforeRender(), after the view class is loaded, and before the
     * Controller::render()
     *
     * @param \Cake\Event\EventInterface $event Event.
     * @return \Cake\Http\Response|null
     */
    public function beforeRender(EventInterface $event)
    {
        /** @var \Cake\Controller\Controller $controller */
        $controller = $event->getSubject();

        if (!$controller->getRequest()->is('ajax')) {
            return null;
        }

        if (
            !in_array('yes', array_map( // case insensitive
                'strtolower',
                $controller->getRequest()->getHeader('X-Get-Flash')
            ), true)
        ) {
            return null;
        }

        $session = $controller->getRequest()->getSession();

        if (!$session->check("Flash")) {
            return null;
        }

        $flash = $session->read("Flash");
        if (!is_array($flash)) {
            throw new UnexpectedValueException('Value for Flash setting must be an array');
        }
        $session->delete('Flash');

        $array = [];
        foreach ($flash as $key => $stack) {
            if (!is_array($stack)) {
                throw new UnexpectedValueException(sprintf(
                    'Value for flash setting key "%s" must be an array.',
                    $key
                ));
            }
            foreach ($stack as $message) {
                $array[$key][] = [
                    'message' => $message['message'] ?? null,
                    'type' => $message['type'] ?? null,
                    'params' => $message['params'] ?? null,
                ];
            }
        }

        // The header can be processed by the client side JavaScript to display flash messages
        $this->getController()->setResponse($controller->getResponse()->withHeader('X-Flash', json_encode($array)));

        return null;
    }

    /**
     * Used to set a session variable that can be used to output messages in the view.
     * If you make consecutive calls to this method, the messages will stack (if they are
     * set with the same flash key)
     *
     * In your controller: $this->Flash->set('This has been saved');
     *
     * ### Options:
     *
     * - `key` The key to set under the session's Flash key
     * - `element` The element used to render the flash message. Default to 'default'.
     * - `params` An array of variables to make available when using an element
     * - `clear` A bool stating if the current stack should be cleared to start a new one
     * - `escape` Set to false to allow templates to print out HTML content
     *
     * @param string|\Exception $message Message to be flashed. If an instance
     *   of \Exception the exception message will be used and code will be set
     *   in params.
     * @param array $options An array of options
     * @return void
     */
    public function set($message, array $options = []): void
    {
        $options += (array)$this->getConfig();

        if ($message instanceof Exception) {
            if (!isset($options['params']['code'])) {
                $options['params']['code'] = $message->getCode();
            }
            $message = $message->getMessage();
        }

        if (isset($options['escape']) && !isset($options['params']['escape'])) {
            $options['params']['escape'] = $options['escape'];
        }

        [$plugin, $element] = pluginSplit($options['element']);

        if ($plugin) {
            $options['element'] = $plugin . '.flash/' . $element;
        } else {
            $options['element'] = 'flash/' . $element;
        }

        $messages = [];
        if (!$options['clear']) {
            $messages = (array)$this->getSession()->read('Flash.' . $options['key']);
        }

        if (!$options['duplicate']) {
            foreach ($messages as $existingMessage) {
                if ($existingMessage['message'] === $message) {
                    return;
                }
            }
        }

        $messages[] = [
            'message' => $message,
            'key' => $options['key'],
            'type' => $options['type'],
            'element' => $options['element'],
            'params' => $options['params'],
        ];

        $this->getSession()->write('Flash.' . $options['key'], $messages);
    }

    /**
     * Magic method for verbose flash methods based on element names.
     *
     * For example: $this->Flash->success('My message') would use the
     * `success.php` element under `templates/Element/Flash` for rendering the
     * flash message.
     *
     * If you make consecutive calls to this method, the messages will stack (if they are
     * set with the same flash key)
     *
     * Note that the parameter `element` will be always overridden. In order to call a
     * specific element from a plugin, you should set the `plugin` option in $args.
     *
     * For example: `$this->Flash->warning('My message', ['plugin' => 'PluginName'])` would
     * use the `warning.php` element under `plugins/PluginName/templates/Element/Flash` for
     * rendering the flash message.
     *
     * @param string $name Element name to use.
     * @param array $args Parameters to pass when calling `FlashComponent::set()`.
     * @return void
     * @throws \Cake\Http\Exception\InternalErrorException If missing the flash message.
     */
    public function __call(string $name, array $args): void
    {
        $element = Inflector::underscore($name);

        if (count($args) < 1) {
            throw new InternalErrorException('Flash message missing.');
        }

        $options = ['element' => $element, 'type' => $name];

        if (!empty($args[1])) {
            if (!empty($args[1]['plugin'])) {
                $options = ['element' => $args[1]['plugin'] . '.' . $element];
                unset($args[1]['plugin']);
            }
            $options += (array)$args[1];
        }

        $this->set($args[0], $options);
    }

    /**
     * Returns current session object from a controller request.
     *
     * @return \Cake\Http\Session
     */
    protected function getSession(): Session
    {
        return $this->getController()->getRequest()->getSession();
    }
}
