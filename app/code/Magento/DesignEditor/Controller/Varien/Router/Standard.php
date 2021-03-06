<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @copyright   Copyright (c) 2013 X.commerce, Inc. (http://www.magentocommerce.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *
 */
namespace Magento\DesignEditor\Controller\Varien\Router;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Standard extends \Magento\Core\Controller\Varien\Router\Base
{
    /**
     * @var \Magento\ObjectManager
     */
    protected $_objectManager;

    /**
     * Routers that must not been matched
     *
     * @var array
     */
    protected $_excludedRouters = array('admin', 'vde');

    /**
     * @param \Magento\Core\Controller\Varien\Action\Factory $controllerFactory
     * @param \Magento\ObjectManager $objectManager
     * @param \Magento\Filesystem $filesystem
     * @param \Magento\Core\Model\App $app
     * @param \Magento\Core\Model\Config\Scope $configScope
     * @param \Magento\Core\Model\Route\Config $routeConfig
     * @param \Magento\Core\Model\Url\SecurityInfoInterface $securityInfo
     * @param \Magento\Core\Model\Store\Config $coreStoreConfig
     * @param \Magento\Core\Model\Config $config
     * @param \Magento\Core\Model\Url $url
     * @param \Magento\Core\Model\StoreManager $storeManager
     * @param \Magento\Core\Model\App\State $appState
     * @param $areaCode
     * @param $baseController
     * @param $routerId
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Core\Controller\Varien\Action\Factory $controllerFactory,
        \Magento\ObjectManager $objectManager,
        \Magento\Filesystem $filesystem,
        \Magento\Core\Model\App $app,
        \Magento\Core\Model\Config\Scope $configScope,
        \Magento\Core\Model\Route\Config $routeConfig,
        \Magento\Core\Model\Url\SecurityInfoInterface $securityInfo,
        \Magento\Core\Model\Store\Config $coreStoreConfig,
        \Magento\Core\Model\Config $config,
        \Magento\Core\Model\Url $url,
        \Magento\Core\Model\StoreManager $storeManager,
        \Magento\Core\Model\App\State $appState,
        $areaCode,
        $baseController,
        $routerId
    ) {
        parent::__construct(
            $controllerFactory,
            $filesystem,
            $app,
            $configScope,
            $coreStoreConfig,
            $routeConfig,
            $securityInfo,
            $config,
            $url,
            $storeManager,
            $appState,
            $areaCode,
            $baseController,
            $routerId
        );
        $this->_objectManager = $objectManager;
    }

    /**
     * Match provided request and if matched - return corresponding controller
     *
     * @param \Magento\Core\Controller\Request\Http $request
     * @return \Magento\Core\Controller\Front\Action|null
     */
    public function match(\Magento\Core\Controller\Request\Http $request)
    {
        // if URL has VDE prefix
        if (!$this->_objectManager->get('Magento\DesignEditor\Helper\Data')->isVdeRequest($request)) {
            return null;
        }

        // user must be logged in admin area
        if (!$this->_objectManager->get('Magento\Backend\Model\Auth\Session')->isLoggedIn()) {
            return null;
        }

        // override VDE configuration
        $this->_overrideConfiguration();

        // prepare request to imitate
        $this->_prepareVdeRequest($request);

        // apply rewrites
        $this->getFront()->applyRewrites($request);

        // match routers
        $controller = null;
        $routers = $this->_getMatchedRouters();
        /** @var $router \Magento\Core\Controller\Varien\Router\AbstractRouter */
        foreach ($routers as $router) {
            /** @var $controller \Magento\Core\Controller\Varien\AbstractAction */
            $controller = $router->match($request);
            if ($controller) {
                $this->_objectManager->get('Magento\DesignEditor\Model\State')
                    ->update($this->_areaCode, $request, $controller);
                break;
            }
        }

        // set inline translation mode
        $this->_objectManager->get('Magento\DesignEditor\Helper\Data')->setTranslationMode($request);

        return $controller;
    }

    /**
     * Modify request path to imitate basic request
     *
     * @param \Magento\Core\Controller\Request\Http $request
     * @return \Magento\DesignEditor\Controller\Varien\Router\Standard
     */
    protected function _prepareVdeRequest(\Magento\Core\Controller\Request\Http $request)
    {
        list($vdeFrontName, $designMode, $themeId) = explode('/', trim($request->getPathInfo(), '/'));
        $request->setAlias('editorMode', $designMode);
        $request->setAlias('themeId', (int)$themeId);
        $vdePath = implode('/', array($vdeFrontName, $designMode, $themeId));
        $noVdePath = substr($request->getPathInfo(), strlen($vdePath) + 1) ?: '/';
        $request->setPathInfo($noVdePath);
        return $this;
    }

    /**
     * Returns list of routers that must been tried to match
     *
     * @return array
     */
    protected function _getMatchedRouters()
    {
        $routers = $this->getFront()->getRouters();
        foreach (array_keys($routers) as $name) {
            if (in_array($name, $this->_excludedRouters)) {
                unset($routers[$name]);
            }
        }
        return $routers;
    }

    /**
     * Override frontend configuration with VDE area data
     */
    protected function _overrideConfiguration()
    {
        $vdeNode = $this->_objectManager->get('Magento\Core\Model\Config')
            ->getNode(\Magento\DesignEditor\Model\Area::AREA_VDE);
        if ($vdeNode) {
            $this->_objectManager->get('Magento\Core\Model\Config')->getNode(\Magento\Core\Model\App\Area::AREA_FRONTEND)
                ->extend($vdeNode, true);
        }
    }
}
