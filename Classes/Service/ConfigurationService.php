<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2012 Claus Due <claus@wildside.dk>, Wildside A/S
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * Configuration Service
 *
 * Provides methods to read various configuration related
 * to Fluid Content Elements.
 *
 * @author Claus Due, Wildside A/S
 * @package Fluidcontent
 * @subpackage Service
 */
class Tx_Fluidcontent_Service_ConfigurationService extends Tx_Flux_Service_FluxService implements \TYPO3\CMS\Core\SingletonInterface {

	/**
	 * @var string
	 */
	protected $defaultIcon;

	/**
	 * CONSTRUCTOR
	 */
	public function __construct() {
		$this->defaultIcon = '../' . \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::siteRelPath('fluidcontent') . 'Resources/Public/Icons/Plugin.png';
	}

	/**
	 * Get definitions of paths for FCEs defined in TypoScript
	 *
	 * @param string $extensionName
	 * @return array
	 * @api
	 */
	public function getContentConfiguration($extensionName = NULL) {
		$cacheKey = NULL === $extensionName ? 0 : $extensionName;
		$cacheKey = 'content_' . $cacheKey;
		if (TRUE === isset(self::$cache[$cacheKey])) {
			return self::$cache[$cacheKey];
		}
		$newLocation = (array) $this->getTypoScriptSubConfiguration($extensionName, 'collections', 'fluidcontent');
		$oldLocation = (array) $this->getTypoScriptSubConfiguration($extensionName, 'fce', 'fed');
		$merged = \TYPO3\CMS\Core\Utility\GeneralUtility::array_merge_recursive_overrule($oldLocation, $newLocation);
		$registeredExtensionKeys = Tx_Flux_Core::getRegisteredProviderExtensionKeys('Content');
		if (NULL === $extensionName) {
			foreach ($registeredExtensionKeys as $registeredExtensionKey) {
				$nativeViewLocation = $this->getContentConfiguration($registeredExtensionKey);
				if (FALSE === isset($nativeViewLocation['extensionKey'])) {
					$nativeViewLocation['extensionKey'] = $registeredExtensionKey;
				}
				self::$cache[$registeredExtensionKey] = $nativeViewLocation;
				$merged[$registeredExtensionKey] = $nativeViewLocation;
			}
		} else {
			$nativeViewLocation = $this->getViewConfigurationForExtensionName($extensionName);
			if (TRUE === is_array($nativeViewLocation)) {
				$merged = \TYPO3\CMS\Core\Utility\GeneralUtility::array_merge_recursive_overrule($nativeViewLocation, $merged);
			}
			if (FALSE === isset($merged['extensionKey'])) {
				$merged['extensionKey'] = \TYPO3\CMS\Core\Utility\GeneralUtility::camelCaseToLowerCaseUnderscored($extensionName);
			}
		}
		self::$cache[$cacheKey] = $merged;
		return $merged;
	}

	/**
	 * @return NULL
	 */
	public function writeCachedConfigurationIfMissing() {
		if (TRUE === file_exists(FLUIDCONTENT_TEMPFILE)) {
			return;
		}
		$templates = $this->getAllRootTypoScriptTemplates();
		$paths = $this->getPathConfigurationsFromRootTypoScriptTemplates($templates);
		$pageTsConfig = '';
		foreach ($paths as $pageUid => $collection) {
			if (FALSE === $collection) {
				continue;
			}
			try {
				$wizardTabs = $this->buildAllWizardTabGroups($collection);
				$collectionPageTsConfig = $this->buildAllWizardTabsPageTsConfig($wizardTabs);
				$pageTsConfig .= '[PIDinRootline = ' . strval($pageUid) . ']' . LF;
				$pageTsConfig .= $collectionPageTsConfig . LF;
				$pageTsConfig .= '[GLOBAL]' . LF;
				$this->message('Built content setup for page ' . $pageUid, \TYPO3\CMS\Core\Utility\GeneralUtility::SYSLOG_SEVERITY_INFO, 'Fluidcontent');
			} catch (Exception $error) {
				$this->debug($error);
			}
		}
		$this->message('Wrote ' . strlen($pageTsConfig) . ' bytes of page TS configuration', \TYPO3\CMS\Core\Utility\GeneralUtility::SYSLOG_SEVERITY_INFO);
		\TYPO3\CMS\Core\Utility\GeneralUtility::writeFile(FLUIDCONTENT_TEMPFILE, $pageTsConfig);
		return NULL;
	}

	/**
	 * Gets a collection of path configurations for content elements
	 * based on each root TypoScript template in the provided array
	 * of templates. Returns an array of paths indexed by the root
	 * page UID.
	 *
	 * @param array $templates
	 * @return array
	 */
	protected function getPathConfigurationsFromRootTypoScriptTemplates($templates) {
		$allTemplatePaths = array();
		$registeredExtensionKeys = Tx_Flux_Core::getRegisteredProviderExtensionKeys('Content');
		foreach ($templates as $templateRecord) {
			$pageUid = $templateRecord['pid'];
			/** @var t3lib_tsparser_ext $template */
			$template = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\TypoScript\\ExtendedTemplateService');
			$template->tt_track = 0;
			$template->init();
			/** @var \TYPO3\CMS\Frontend\Page\PageRepository $sys_page */
			$sys_page = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Page\\PageRepository');
			$rootLine = $sys_page->getRootLine($pageUid);
			$template->runThroughTemplates($rootLine);
			$template->generateConfig();
			$oldTemplatePathLocation = (array) $template->setup['plugin.']['tx_fed.']['fce.'];
			$newTemplatePathLocation = (array) $template->setup['plugin.']['tx_fluidcontent.']['collections.'];
			$registeredPathCollections = array();
			foreach ($registeredExtensionKeys as $registeredExtensionKey) {
				$nativeViewLocation = $this->getContentConfiguration($registeredExtensionKey);
				if (FALSE === isset($nativeViewLocation['extensionKey'])) {
					$nativeViewLocation['extensionKey'] = $registeredExtensionKey;
				}
				$registeredPathCollections[$registeredExtensionKey] = $nativeViewLocation;
			}
			$merged = \TYPO3\CMS\Core\Utility\GeneralUtility::array_merge_recursive_overrule($oldTemplatePathLocation, $newTemplatePathLocation);
			$merged = \TYPO3\CMS\Core\Utility\GeneralUtility::removeDotsFromTS($merged);
			$merged = \TYPO3\CMS\Core\Utility\GeneralUtility::array_merge($merged, $registeredPathCollections);
			$allTemplatePaths[$pageUid] = $merged;
		}
		return $allTemplatePaths;
	}

	/**
	 * @return array
	 */
	protected function getAllRootTypoScriptTemplates() {
		$condition = 'deleted = 0 AND hidden = 0  AND starttime<=' . $GLOBALS['SIM_ACCESS_TIME'] . ' AND (endtime=0 OR endtime>' . $GLOBALS['SIM_ACCESS_TIME'] . ')';
		$condition .= ' AND pid IN (SELECT uid FROM pages WHERE deleted = 0)'; // Make sure template is not on deleted page
		$rootTypoScriptTemplates = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('pid', 'sys_template', $condition);
		return $rootTypoScriptTemplates;
	}

	/**
	 * Scans all folders in $allTemplatePaths for template
	 * files, reads information about each file and collects
	 * the groups of files into groups of pageTSconfig setup.
	 *
	 * @param array $allTemplatePaths
	 * @return array
	 */
	protected function buildAllWizardTabGroups($allTemplatePaths) {
		$wizardTabs = array();
		foreach ($allTemplatePaths as $key => $templatePathSet) {
			$key = trim($key, '.');
			$extensionKey = TRUE === isset($templatePathSet['extensionKey']) ? $templatePathSet['extensionKey'] : $key;
			$paths = array(
				'templateRootPath' => TRUE === isset($templatePathSet['templateRootPath']) ? $templatePathSet['templateRootPath'] : 'EXT:' . $extensionKey . '/Resources/Private/Templates/',
				'layoutRootPath' => TRUE === isset($templatePathSet['layoutRootPath']) ? $templatePathSet['layoutRootPath'] : 'EXT:' . $extensionKey . '/Resources/Private/Layouts/',
				'partialRootPath' => TRUE === isset($templatePathSet['partialRootPath']) ? $templatePathSet['partialRootPath'] : 'EXT:' . $extensionKey . '/Resources/Private/Partials/',
			);
			$paths = Tx_Flux_Utility_Path::translatePath($paths);
			$templateRootPath = $paths['templateRootPath'];
			if ('/' !== substr($templateRootPath, -1)) {
				$templateRootPath .= '/';
			}
			if (TRUE === file_exists($templateRootPath . 'Content/')) {
				$templateRootPath = $templateRootPath . 'Content/';
			}
			$files = array();
			$files = \TYPO3\CMS\Core\Utility\GeneralUtility::getAllFilesAndFoldersInPath($files, $templateRootPath, 'html');
			if (count($files) > 0) {
				foreach ($files as $templateFilename) {
					$fileRelPath = substr($templateFilename, strlen($templateRootPath));
					$form = $this->getFormFromTemplateFile($templateFilename, 'Configuration', 'form', $paths, $extensionKey);
					if (TRUE === empty($form)) {
						$this->sendDisabledContentWarning($templateFilename);
						continue;
					}
					if (FALSE === $form->getEnabled()) {
						$this->sendDisabledContentWarning($templateFilename);
						continue;
					}
					$group = $form->getGroup();
					if (FALSE === empty($group)) {
						$tabId = $this->sanitizeString($group);
						$wizardTabs[$tabId]['title'] = $group;
					} else {
						$tabId = 'Content';
					}
					$id = $key . '_' . preg_replace('/[\.\/]/', '_', $fileRelPath);
					$elementTsConfig = $this->buildWizardTabItem($tabId, $id, $form, $key . ':' . $fileRelPath);
					$wizardTabs[$tabId]['elements'][$id] = $elementTsConfig;
					$wizardTabs[$tabId]['key'] = $extensionKey;
				}
			}
		}
		return $wizardTabs;
	}

	/**
	 * Builds a big piece of pageTSconfig setup, defining
	 * every detected content element's wizard tabs and items.
	 *
	 * @param array $wizardTabs
	 * @return string
	 */
	protected function buildAllWizardTabsPageTsConfig($wizardTabs) {
		$pageTsConfig = '';
		foreach ($wizardTabs as $tab) {
			foreach ($tab['elements'] as $elementTsConfig) {
				$pageTsConfig .= $elementTsConfig;
			}
		}
		foreach ($wizardTabs as $tabId => $tab) {
			$pageTsConfig .= sprintf('
				mod.wizards.newContentElement.wizardItems.%s {
					header = %s
					show = %s
					position = 0
					key = %s
				}
				',
				$tabId,
				$tab['title'],
				implode(',', array_keys($tab['elements'])),
				$tab['key']
			);
		}
		return $pageTsConfig;
	}

	/**
	 * Builds a single Wizard item (one FCE) based on the
	 * tab id, element id, configuration array and special
	 * template identity (groupName:Relative/Path/File.html)
	 *
	 * @param string $tabId
	 * @param string $id
	 * @param Tx_Flux_Form $form
	 * @param string $templateFileIdentity
	 * @return string
	 */
	protected function buildWizardTabItem($tabId, $id, $form, $templateFileIdentity) {
		$icon = $form->getIcon();
		$iconFileRelativePath = ($icon ? $icon : $this->defaultIcon);
		return sprintf('
			mod.wizards.newContentElement.wizardItems.%s.elements.%s {
				icon = %s
				title = %s
				description = %s
				tt_content_defValues {
					CType = fluidcontent_content
					tx_fed_fcefile = %s
				}
			}
			',
			$tabId,
			$id,
			$iconFileRelativePath,
			$form->getLabel(),
			$form->getDescription(),
			$templateFileIdentity
		);
	}

	/**
	 * @param string $string
	 * @return string
	 */
	protected function sanitizeString($string) {
		$pattern = '/([^a-z0-9\-]){1,}/i';
		$string = preg_replace($pattern, '-', $string);
		return trim($string, '-');
	}

	/**
	 * @param string $templatePathAndFilename
	 * @return void
	 */
	protected function sendDisabledContentWarning($templatePathAndFilename) {
		$this->message('Disabled Fluid Content Element: ' . $templatePathAndFilename, \TYPO3\CMS\Core\Utility\GeneralUtility::SYSLOG_SEVERITY_NOTICE);
	}

}
