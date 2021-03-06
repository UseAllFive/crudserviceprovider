<?php

namespace Mparaiso\CRUD\Controller;

use Mparaiso\Crud\BulkType;
use Mparaiso\Crud\ICrudService;
use ReflectionClass;
use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\SecurityContext;

/**
 * FR : classe générique pour un classique CRUD.
 * EN : generic class for classical CRUD operations
 * @author M.Paraiso
 * copyright 2013 M.Paraiso
 * contact mparaiso@online.fr
 */
class CRUDController implements ControllerProviderInterface {

    public $templateLayout = 'crud/layout.html.twig';
    public $formTemplate = 'crud/includes/form.html.twig';

    public $model;
    public $form;
    // id
    public $getIdMethod = "getId";
    /**
     *
     * @var ICRUDService
     */
    public $service;
    public $resource;
    public $collectionName;
    public $beforeCreateEvent;
    public $afterCreateEvent;
    public $beforeUpdateEvent;
    public $afterUpdateEvent;
    public $beforeDeleteEvent;
    public $afterDeleteEvent;
    public $indexTemplate = "crud/resource_index.html.twig";
    public $readTemplate = "crud/resource_read.html.twig";
    public $createTemplate = "crud/resource_create.html.twig";
    public $updateTemplate = "crud/resource_update.html.twig";
    public $deleteTemplate = "crud/resource_delete.html.twig";
    public $indexRoute;
    public $readRoute;
    public $createRoute;
    public $updateRoute;
    public $deleteRoute;
    public $indexCallback = "index";
    public $readCallback = "read";
    public $createCallback = "create";
    public $updateCallback = "update";
    public $deleteCallback = "delete";
    public $createMethod = "save";
    public $updateMethod = "save";
    public $deleteMethod = "delete";
    public $readMethod = "find";
    public $indexMethod = "findBy";
    public $countMethod = "count";
    public $userAware = FALSE;
    public $userEntityProperty = "user";
    public $createSuccessMessage = "%s with id %s was created successfully !";
    public $deleteSuccessMessage = "%s with id %s was deleted successfully !";
    public $updateSuccessMessage = "%s with id %s was updated successfully !";
    public $propertyList = array();
    public $orderList = array();
    // pagination
    public $limit = 20;
    public $order = array("id" => "DESC");
    public $bulkActionRoute;
    public $bulkActions;

    /**
     * FR : un controller crud de base pour éviter de recoder les fonctionnalités classiques d'une application <br/>
     * EN : a basic crud controller that implements basic web app functionalities on a resource ( READ , CREATE , UPDATE , DELETE )
     * values can be passed in the constructor to configure the crud controller.
     * @param array $values
     */
    function __construct(array $values = array()) {
        foreach ($values as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        if (NULL === $this->collectionName)
            $this->collectionName = $this->resource . "Collection";
        if (NULL === $this->beforeCreateEvent) {
            $this->beforeCreateEvent = $this->resource . "_before_create";
        }

        if (NULL === $this->resource)
            throw new \Exception("resource cannot be null");

        if (NULL === $this->afterCreateEvent) {
            $this->afterCreateEvent = $this->resource . "_after_create";
        }
        if (NULL === $this->beforeUpdateEvent) {
            $this->beforeUpdateEvent = $this->resource . "_before_update";
        }
        if (NULL === $this->afterUpdateEvent) {
            $this->afterUpdateEvent = $this->resource . "_after_update";
        }
        if (NULL === $this->beforeDeleteEvent) {
            $this->beforeDeleteEvent = $this->resource . "_before_delete";
        }
        if (NULL === $this->afterDeleteEvent) {
            $this->afterDeleteEvent = $this->resource . "_after_delete";
        }
        if (NULL === $this->indexRoute) {
            $this->indexRoute = "mp_crud_" . $this->resource . "_index";
        }
        if (NULL === $this->readRoute) {
            $this->readRoute = "mp_crud_" . $this->resource . "_read";
        }
        if (NULL === $this->createRoute) {
            $this->createRoute = "mp_crud_" . $this->resource . "_create";
        }
        if (NULL === $this->updateRoute) {
            $this->updateRoute = "mp_crud_" . $this->resource . "_update";
        }
        if (NULL === $this->deleteRoute) {
            $this->deleteRoute = "mp_crud_" . $this->resource . "_delete";
        }

        $this->bulkActionRoute = "mp_crud_" . $this->resource . "_bulk";

        if (NULL === $this->bulkActions) {
            $this->bulkActions = array(
                "bulkDelete" => "Delete selected resources",
            );
        }
    }

    /**
     * FR : création des routes et de leurs callbacks respectives
     * EN : routes and callbacks creation to be appended to the app routes.
     * @param \Silex\Application $app
     * @return \Silex\ControllerCollection
     */
    function connect(Application $app) {
        /** @public $controllers \Silex\ControllerCollection */
        $controllers = $app["controllers_factory"];
        // READ
        $read = $controllers->match("/" . $this->resource . "/read/{id}", array($this, "$this->readCallback"))
            ->assert("id", '\d+|\w+')
            ->bind($this->readRoute);
        // CREATE
        $controllers->match("/" . $this->resource . "/create", array($this, "$this->createCallback"))
            ->value('format', 'html')
            ->bind($this->createRoute);
        // UPDATE
        $update = $controllers->match("/{$this->resource}/update/{id}", array($this, "$this->updateCallback"))
            ->value('format', 'html')
            ->assert("id", '\d+|\w+')
            ->bind($this->updateRoute);
        // DELETE
        $delete = $controllers->match("/{$this->resource}/delete/{id}", array($this, "$this->deleteCallback"))
            ->assert("id", '\d+|\w+')
            ->value('format', 'html')
            ->method('GET|POST')
            ->bind($this->deleteRoute);

        $bulk = $controllers->post("/{$this->resource}/bulk", array($this, 'bulkAction'))
            ->bind($this->bulkActionRoute);

        $index = $controllers->match("/" . $this->resource, array($this, "$this->indexCallback"))
            ->value('format', 'html')
            ->bind($this->indexRoute);

        return $controllers;
    }

    function index(Request $req, Application $app) {
        $limit = $this->limit;
        $queryoffset = (int)$req->query->get("offset", 0);
        $order = array();
        foreach ($this->orderList as $prop) {
            $value = $req->query->get($prop);
            if ($value != NULL)
                $order[$prop] = $value;
        }
        $order = count($order) === 0 ? $this->order : $order;
        $offset = $queryoffset * $limit;
        $resources = $this->service->{$this->indexMethod}(array(), $order, $limit, $offset);
        $total = $this->service->{$this->countMethod}();
        /* map ids and exchange keys and values */
        $map = array();
        foreach ($resources as $resource) {
            $map[] = (string)$resource->{$this->getIdMethod}();
        }
        //$app["logger"]->log(print_r($map));
        $type = new BulkType(array_flip($map), $this->bulkActions);
        $bulkForm = $app['form.factory']->create($type);
        /* EN : execute bulk operations */
        if ("POST" === $req->getMethod()) {
            $bulkForm->bind($req);
            if ($bulkForm->isValid()) {
                $action = $bulkForm->get('action')->getData();
                $ids = $bulkForm->get('ids')->getData();
                $this->{$action}($ids, $app);
                $app['session']->getFlashBag()->set('success', "The bulk operation \"$action\" has been successfully completed");
                return $app->redirect($app['url_generator']->generate(
                    $this->indexRoute, array("offset" => $queryoffset)));
            }
        }
        return $app["twig"]
            ->render($this->indexTemplate, array("resources" => $resources,
                "offset" => $queryoffset,
                "limit" => $this->limit,
                "total" => $total,
                "layout" => $this->templateLayout,
                "resourceName" => $this->resource,
                "updateRoute" => $this->updateRoute,
                "deleteRoute" => $this->deleteRoute,
                "createRoute" => $this->createRoute,
                "readRoute" => $this->readRoute,
                "propertyList" => $this->propertyList,
                "orderList" => $this->orderList,
                "bulkActionForm" => $bulkForm->createView(),
            ));
    }

    /**
     * FR : affiche une resource
     * EN : show a resource
     * @param unknown $id
     * @param Request $req
     * @param Application $app
     * @param unknown $format
     */
    function read($id, Request $req, Application $app) {
        $resource = $this->service->{$this->readMethod}($id);
        $resource === NULL AND $app->abort(404, "resource not found");

        $reflect = new \ReflectionClass($this->model);
        if (!$properties = $this->propertyList) {
            $properties = array_map(function ($prop) {
                /* @public $prop \ReflectionProperty */
                return $prop->getName();
            }, $reflect->getProperties());
        }
        return $app["twig"]
            ->render($this->readTemplate, array(
                "resourceName" => $this->resource,
                'resource' => $resource,
                'properties' => $properties,
                "layout" => $this->templateLayout,
                "indexRoute" => $this->indexRoute,
                "updateRoute" => $this->updateRoute
            ));
    }

    /**
     * FR : Créer une resource
     * EN : create a resource
     * @param Application $app
     * @param Request $req
     * @param string $format
     */
    function create(Application $app, Request $req) {
        $resource = new $this->model();
        /* @public $form Form */
        $form = $app["form.factory"]->create(new $this->form(), $resource);
        if ("POST" === $req->getMethod()) {
            $form->bind($req);
            if ($form->isValid()) {
                $app['dispatcher']->dispatch($this->beforeCreateEvent, new GenericEvent($resource, array(
                    'form' => $form, "app" => $app, 'request' => $req)));
                $this->service->{$this->createMethod}($resource);
                $app['dispatcher']->dispatch($this->afterCreateEvent, new GenericEvent($resource, array(
                    'form' => $form, "app" => $app, 'request' => $req)));
                $app["session"]->getFlashBag()->add("info", sprintf($this->createSuccessMessage, $this->resourceName, $resource->{$this->getIdMethod}()));
                return $app->redirect($app['url_generator']
                    ->generate($this->readRoute, array("id" => $resource->{$this->getIdMethod}())));
            }
        }
        return $app["twig"]
            ->render($this->createTemplate, array(
                "resourceName" => $this->resource,
                "form" => $form->createView(),
                "layout" => $this->templateLayout,
                "formTemplate" => $this->formTemplate,
                "indexRoute" => $this->indexRoute
            ));
    }

    /**
     * FR : supprime une resource
     * EN : delete a resource
     * @param \Silex\Application $app
     * @param \Symfony\Component\HttpFoundation\Request $req
     * @param $format
     * @param $id
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    function delete(Application $app, Request $req, $format, $id) {
        $resource = $this->service->{$this->readMethod}($id);
        $resource === NULL AND $app->abort(404, "resource not found");
        if ("POST" === $req->getMethod()) {
            $app['dispatcher']->dispatch($this->beforeDeleteEvent, new GenericEvent($resource, array(
                "app" => $app)));
            $count = $this->service->{$this->deleteMethod}($resource);
            $app['dispatcher']->dispatch($this->afterDeleteEvent, new GenericEvent($resource, array(
                "app" => $app)));
            $app["session"]->getFlashBag()->set("info", sprintf($this->deleteSuccessMessage, $this->resourceName, $id));
            return $app->redirect($app['url_generator']->generate($this->indexRoute));
        } else {
            return $app['twig']->render($this->deleteTemplate, array(
                "resourceName" => $this->resource,
                "resource" => $resource,
                "layout" => $this->templateLayout,
                "indexRoute" => $this->indexRoute
            ));
        }
    }

    function update(Application $app, Request $req, $id) {
        $resource = $this->service->find($id);
        if ($resource === NULL)
            $app->abort(404, "resource not found");
        /* @public $form \Symfony\Component\Form\Form */
        $form = $app["form.factory"]->create(new $this->form(), $resource);
        if ("POST" === $req->getMethod()) {
            $form->bind($req);
            if ($form->isValid()) {
                $app['dispatcher']->dispatch($this->beforeUpdateEvent, new GenericEvent($resource, array(
                    'form' => $form, "app" => $app)));
                $this->service->{$this->updateMethod}($resource);
                $app['dispatcher']->dispatch($this->afterUpdateEvent, new GenericEvent($resource, array(
                    'form' => $form, "app" => $app)));
                $app["session"]->getFlashBag()->add("info", sprintf($this->updateSuccessMessage, $this->resourceName, $resource->{$this->getIdMethod}()));
                return $app->redirect(
                    $app["url_generator"]->generate($this->readRoute, array('id' => $resource->{$this->getIdMethod}()))
                );
            }
        }
        return $app["twig"]->render($this->updateTemplate, array('form' => $form->createView(),
            "resource" => $resource,
            "resourceName" => $this->resource,
            "layout" => $this->templateLayout,
            "formTemplate" => $this->formTemplate,
            "indexRoute" => $this->indexRoute
        ));
    }

    function getCurrentUser(SecurityContext $security) {
        $user = $security->getToken()->getUser();

        return $user;
    }

    /**
     * EN : Delete Multiple resources<br/>
     * FR : supprimer plusieurs resources
     * @param array $ids
     * @return bool
     */
    function bulkDelete(array $ids, Application $app) {
        array_walk($ids, function ($id) use ($app) {
            $resource = $this->service->{$this->readMethod}($id);
            if ($resource != NULL) {
                $app['dispatcher']->dispatch($this->beforeDeleteEvent, new GenericEvent($resource));
                $this->service->{$this->deleteMethod}($resource);
                $app['dispatcher']->dispatch($this->afterDeleteEvent, new GenericEvent($resource));
                return TRUE;
            }
        });
        return TRUE;
    }

}
