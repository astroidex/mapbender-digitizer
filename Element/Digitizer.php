<?php

namespace Mapbender\DigitizerBundle\Element;

use Doctrine\DBAL\DBALException;
use Mapbender\CoreBundle\Element\HTMLElement;
use Mapbender\CoreBundle\Entity\Application;
use Mapbender\CoreBundle\Component\Application as AppComponent;
use Mapbender\CoreBundle\Entity\Element;
use Mapbender\DigitizerBundle\Entity\Feature;
use Mapbender\DigitizerBundle\Entity\FeatureType;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 *
 */
class Digitizer extends HTMLElement
{

    /**
     * @inheritdoc
     */
    static public function getClassTitle()
    {
        return "Digitizer";
    }

    /**
     * @inheritdoc
     */
    static public function getClassDescription()
    {
        return "Digitizer";
    }

    /**
     * @inheritdoc
     */
    static public function getTags()
    {
        return array();
    }

    /**
     * @inheritdoc
     */
    public function getWidgetName()
    {
        return 'mapbender.mbDigitizer';
    }

    /**
     * @inheritdoc
     */
    static public function listAssets()
    {
        return array('js'    => array(
                        '../../vendor/blueimp/jquery-file-upload/js/jquery.fileupload.js',
                        '../../vendor/blueimp/jquery-file-upload/js/jquery.iframe-transport.js',
                        "/components/jquery-context-menu/jquery-context-menu-built.js",
                        'mapbender.container.info.js',
                        'mapbender.element.digitizer.js'
        ),
                     'css'   => array('sass/element/digitizer.scss'),
                     'trans' => array('@MapbenderDigitizerBundle/Resources/views/Element/digitizer.json.twig'));
    }

    /**
     * @inheritdoc
     */
    public static function getDefaultConfiguration()
    {
        return array(
            "target" => null
        );
    }

    /**
     * Prepare form items for each scheme definition
     * Optional: get featureType by name from global context.
     *
     * @inheritdoc
     */
    public function getConfiguration()
    {
        $configuration          = parent::getConfiguration();
        $configuration['debug'] = isset($configuration['debug']) ? $configuration['debug'] : false;

        if ($configuration["schemes"] && is_array($configuration["schemes"])) {
            foreach ($configuration["schemes"] as $key => &$scheme) {
                if (is_string($scheme['featureType'])) {
                    $featureTypes          = $this->container->getParameter('featureTypes');
                    $scheme['featureType'] = $featureTypes[$scheme['featureType']];
                }
                if (isset($scheme['formItems'])) {
                    $scheme['formItems'] = $this->prepareItems($scheme['formItems']);
                }
            }
        }
        return $configuration;
    }

    /**
     * @inheritdoc
     */
    public static function getType()
    {
        return 'Mapbender\DigitizerBundle\Element\Type\DigitizerAdminType';
    }

    /**
     * @inheritdoc
     */
    public static function getFormTemplate()
    {
        return 'MapbenderDigitizerBundle:ElementAdmin:digitizeradmin.html.twig';
    }

    /**
     * @inheritdoc
     */
    public function render()
    {
        return $this->container->get('templating')
            ->render('MapbenderDigitizerBundle:Element:digitizer.html.twig',
                array(
                    'id'            => $this->getId(),
                    'title'         => $this->getTitle(),
                    'configuration' => $this->getConfiguration()
                ));
    }

    /**
     * Prepare request feautre data by the form definition
     *
     * @param $feature
     * @param $formItems
     * @return array
     */
    protected function prepareQueredFeatureData($feature, $formItems)
    {
        foreach ($formItems as $key => $formItem) {
            if (isset($formItem['children'])) {
                $feature = array_merge($feature, $this->prepareQueredFeatureData($feature, $formItem['children']));
            } elseif (isset($formItem['type']) && isset($formItem['name'])) {
                switch ($formItem['type']) {
                    case 'select':
                        if (isset($formItem['multiple'])) {
                            $fieldType                  = isset($formItem['fieldType']) ? $formItem['fieldType'] : 'text';
                            $separator                  = isset($formItem['separator']) ? $formItem['separator'] : ',';
                            if(is_array($feature["properties"][$formItem['name']])){
                                $feature["properties"][$formItem['name']] = implode($separator, $feature["properties"][$formItem['name']]);
                            }
                        }
                        break;
                }
            }
        }
        return $feature;
    }

    /**
     * @inheritdoc
     */
    public function httpAction($action)
    {
        /**
         * @var $requestService Request
         */
        $configuration   = $this->getConfiguration();
        $requestService  = $this->container->get('request');
        $request         = json_decode($requestService->getContent(), true);
        $schemas         = $configuration["schemes"];
        $debugMode       = $configuration['debug'] || $this->container->get('kernel')->getEnvironment() == "dev";
        $schemaName      = isset($request["schema"]) ? $request["schema"] : $requestService->get("schema");
        $defaultCriteria = array('returnType' => 'FeatureCollection',
                                 'maxResults' => 2);
        if (empty($schemaName)) {
            throw new Exception('For initialization there is no name of the declared scheme');
        }

        $schema     = $schemas[$schemaName];

        if (is_array($schema['featureType'])) {
            $featureType = new FeatureType($this->container, $schema['featureType']);
        } else {
            throw new Exception("FeatureType settings not correct");
        }

        $results = array();

        switch ($action) {
            case 'select':

                $results         = $featureType->search(array_merge($defaultCriteria, $request));
                break;

            case 'save':
                // save once
                if (isset($request['feature'])) {
                    $request['features'] = array($request['feature']);
                }

                try {
                    // save collection
                    if (isset($request['features']) && is_array($request['features'])) {
                        foreach ($request['features'] as $feature) {
                            /**
                             * @var $feature Feature
                             */
                            $featureData = $this->prepareQueredFeatureData($feature, $schema['formItems']);
                            $feature = $featureType->save($featureData);
                            $results = array_merge($featureType->search(array(
                                'where' => $featureType->getUniqueId() . '=' . $feature->getId())));
                        }
                    }
                    $results = $featureType->toFeatureCollection($results);
                } catch (DBALException $e) {
                    $message = $debugMode ? $e->getMessage() : "Feature can't be saved. Maybe something is wrong configured or your database isn't available?\n" .
                        "For more information have a look at the webserver log file. \n Error code: " .$e->getCode();
                    $results = array('errors' => array(
                        array('message' => $message, 'code' => $e->getCode())
                    ));
                }

                break;

            case 'delete':
                $results = $featureType->remove($request['feature'])->getId();
                break;

            case 'file-upload':
                /**
                 * Application entity.
                 * Holds element configuration
                 * @var $elementEntity Mapbender\CoreBundle\Entity\Element
                 */

                /**
                 * @var $application Application
                 */
                /**
                 * Application short name used as ID
                 *
                 * @var $slug string
                 */
//                $applications         = $this->container->get('mapbender')->getApplicationEntities();
//                $uploads_web_url      = AppComponent::getUploadsUrl($this->container);



//                $options = array(
//                    'upload_dir' => 'Your upload directory',
//                    'accept_file_types' => '/\.(gif|jpe?g|png)$/i'
//                );
//                $upload_handler = new \UploadHandler($options);

                $featureTypeRootDirName    = "featureTypes";
                $baseUploadDir             = realpath(AppComponent::getUploadsDir($this->container));
                $featureTypesUploadDir     = $baseUploadDir . "/" . $featureTypeRootDirName;
                $shemaUploadDir            = $featureTypesUploadDir . "/" . $schemaName;
                $fid                       = $requestService->get('fid');
                $feature                   = $featureType->getById($fid);
                $results["feature"]        = $feature->toGeoJson();
                $results["fid"]            = $fid;
                $results["schemaName"]     = $schemaName;
                $results["schema"]         = $schema;
                $results["shemaUploadDir"] = $shemaUploadDir;


                $results["webUrl"]  = AppComponent::getUploadsUrl($this->container);
                $results["fileDir"]  = realpath(AppComponent::getUploadsDir($this->container)) ;


                $elementEntity      = $this->getEntity();
                $elemntId           = $elementEntity->getId();
                $application        = $elementEntity->getApplication();
                $slug               = $application->getSlug();


                AppComponent::createAppWebDir($this->container, $slug);


                $results["webUrl"]  = AppComponent::getAppWebUrl($this->container, $slug);
                $results["slug"]    = $slug;


                //                $results["list"]   = $featureType->search(array("returnType" => 'FeatureCollection'));

                break;

            default:
                $results = array(
                    array('errors' => array(
                        array('message' => $action . " not defined!")
                    ))
                );
        }

        return new JsonResponse($results);
    }

    /**
     * Get assets. This method is overloaded,
     * course of needing to aggregate CSS from configuration.
     *
     * @inheritdoc
     */
    public function getAssets()
    {
        $configuration = $this->getConfiguration();
        $assets        = parent::getAssets();
        if (isset($configuration['css'])) {
            if (is_array($configuration['css'])) {
                $assets['css'] = array_merge($assets['css'], $configuration['css']);
            } else {
                $assets['css'][] = $configuration['css'];
            }
        }
        return $assets;
    }
}