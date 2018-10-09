<?php

namespace Icinga\Module\Director\DirectorObject\Automation;

use Icinga\Module\Director\Core\Json;
use Icinga\Module\Director\Db;
use Icinga\Module\Director\Data\Db\DbObject;
use Icinga\Module\Director\Objects\DirectorDatafield;
use Icinga\Module\Director\Objects\IcingaCommand;
use Icinga\Module\Director\Objects\IcingaObject;
use RuntimeException;
use Zend_Db_Adapter_Abstract as ZfDbAdapter;

class BasketSnapshot extends DbObject
{
    protected $objects = [];

    protected $content;

    protected $table = 'director_basket_snapshot';

    protected $keyName = [
        'basket_uuid',
        'ts_create',
    ];

    protected $restoreOrder = [
        'Datafield',
        'Command',
        'HostGroup',
        'IcingaTemplateChoiceHost',
        'HostTemplate',
        'ServiceGroup',
        'IcingaTemplateChoiceService',
        'ServiceTemplate',
        'ServiceSet',
        'Notification',
        'Dependency',
        'ImportSource',
        'SyncRule',
        'DirectorJob',
        'Basket',
    ];

    protected $defaultProperties = [
        'basket_uuid'      => null,
        'content_checksum' => null,
        'ts_create'        => null,
    ];

    public static function getClassForType($type)
    {
        $types = [
            'Datafield'       => '\\Icinga\\Module\\Director\\Objects\\DirectorDatafield',
            'Command'         => '\\Icinga\\Module\\Director\\Objects\\IcingaCommand',
            'HostGroup'       => '\\Icinga\\Module\\Director\\Objects\\IcingaHostGroup',
            'IcingaTemplateChoiceHost' => '\\Icinga\\Module\\Director\\Objects\\IcingaTemplateChoiceHost',
            'HostTemplate'    => '\\Icinga\\Module\\Director\\Objects\\IcingaHost',
            'ServiceGroup'    => '\\Icinga\\Module\\Director\\Objects\\IcingaServiceGroup',
            'IcingaTemplateChoiceService' => '\\Icinga\\Module\\Director\\Objects\\IcingaTemplateChoiceService',
            'ServiceTemplate' => '\\Icinga\\Module\\Director\\Objects\\IcingaService',
            'ServiceSet'      => '\\Icinga\\Module\\Director\\Objects\\IcingaServiceSet',
            'Notification'    => '\\Icinga\\Module\\Director\\Objects\\IcingaNotification',
            'Dependency'      => '\\Icinga\\Module\\Director\\Objects\\IcingaDependency',
            'ImportSource'    => '\\Icinga\\Module\\Director\\Objects\\ImportSource',
            'SyncRule'        => '\\Icinga\\Module\\Director\\Objects\\SyncRule',
            'DirectorJob'     => '\\Icinga\\Module\\Director\\Objects\\DirectorJob',
            'Basket'          => '\\Icinga\\Module\\Director\\DirectorObject\\Automation\\Automation',
        ];

        return $types[$type];
    }

    /**
     * @param Basket $basket
     * @param Db $db
     * @return BasketSnapshot
     * @throws \Icinga\Exception\NotFoundError
     */
    public static function createForBasket(Basket $basket, Db $db)
    {
        $snapshot = static::create([
            'basket_uuid' => $basket->get('uuid')
        ], $db);
        $snapshot->addObjectsChosenByBasket($basket);
        $snapshot->resolveRequiredFields();

        return $snapshot;
    }

    /**
     * @throws \Icinga\Exception\NotFoundError
     */
    protected function resolveRequiredFields()
    {
        $requiredIds = [];
        foreach ($this->objects as $typeName => $objects) {
            foreach ($objects as $key => $object) {
                if (isset($object->fields)) {
                    foreach ($object->fields as $field) {
                        $requiredIds[$field->datafield_id] = true;
                    }
                }
            }
        }

        $connection = $this->getConnection();
        if (! isset($this->objects['Datafield'])) {
            $this->objects['Datafield'] = [];
        }
        $fields = & $this->objects['Datafield'];
        foreach (array_keys($requiredIds) as $id) {
            if (! isset($fields[$id])) {
                $fields[$id] = DirectorDatafield::loadWithAutoIncId((int) $id, $connection)->export();
            }
        }

        if (empty($this->objects['Datafield'])) {
            unset($this->objects['Datafield']);
        }
    }

    protected function addObjectsChosenByBasket(Basket $basket)
    {
        foreach ($basket->getChosenObjects() as $typeName => $selection) {
            if ($selection === true) {
                $this->addAll($typeName);
            } elseif (! empty($selection)) {
                $this->addByIdentifiers($typeName, $selection);
            }
        }
    }

    /**
     * @throws \Icinga\Module\Director\Exception\DuplicateKeyException
     * @throws \Icinga\Exception\NotFoundError
     */
    protected function beforeStore()
    {
        if ($this->hasBeenLoadedFromDb()) {
            throw new RuntimeException('A basket snapshot cannot be modified');
        }
        $json = $this->getJsonDump();
        $checksum = sha1($json, true);
        if (! BasketContent::exists($checksum, $this->getConnection())) {
            BasketContent::create([
                'checksum' => $checksum,
                'summary'  => $this->getJsonSummary(),
                'content'  => $json,
            ], $this->getConnection())->store();
        }

        $this->set('content_checksum', $checksum);
        $this->set('ts_create', round(microtime(true) * 1000));
    }

    /**
     * @param Db $connection
     * @param bool $replace
     * @throws \Icinga\Module\Director\Exception\DuplicateKeyException
     * @throws \Icinga\Exception\NotFoundError
     * @throws \Zend_Db_Adapter_Exception
     */
    public function restoreTo(Db $connection, $replace = true)
    {
        static::restoreJson(
            $this->getJsonDump(),
            $connection,
            $replace
        );
    }

    public static function restoreJson($string, Db $connection, $replace = true)
    {
        $snapshot = new static();
        $snapshot->restoreObjects(
            Json::decode($string),
            $connection,
            $replace
        );
    }

    /**
     * @param $all
     * @param Db $connection
     * @param bool $replace
     * @throws \Icinga\Module\Director\Exception\DuplicateKeyException
     * @throws \Zend_Db_Adapter_Exception
     */
    protected function restoreObjects($all, Db $connection, $replace = true)
    {
        $db = $connection->getDbAdapter();
        $db->beginTransaction();
        $fieldMap = [];
        foreach ($this->restoreOrder as $typeName) {
            if (isset($all->$typeName)) {
                $objects = $all->$typeName;
                $class = static::getClassForType($typeName);

                $changed = [];
                foreach ($objects as $key => $object) {
                    /** @var DbObject $new */
                    $new = $class::import($object, $connection, $replace);
                    if ($new->hasBeenModified()) {
                        if ($new instanceof IcingaObject && $new->supportsImports()) {
                            $changed[$new->getObjectName()] = $new;
                        } else {
                            $new->store();
                        }
                    }
                    if ($new instanceof DirectorDatafield) {
                        $fieldMap[(int) $key] = (int) $new->get('id');
                    }

                    if ($new instanceof IcingaObject) {
                        $this->relinkObjectFields($db, $new, $object, $fieldMap);
                    }
                }

                /** @var IcingaObject $object */
                foreach ($changed as $object) {
                    $this->recursivelyStore($object, $changed);
                }
            }
        }
        $db->commit();
    }

    /**
     * @param ZfDbAdapter $db
     * @param IcingaObject $new
     * @param $object
     * @param $fieldMap
     * @throws \Zend_Db_Adapter_Exception
     */
    protected function relinkObjectFields(ZfDbAdapter $db, IcingaObject $new, $object, $fieldMap)
    {
        if (! $new->supportsFields() || ! isset($object->fields)) {
            return;
        }

        $objectId = (int) $new->get('id');
        $table = $new->getTableName() . '_field';
        $objectKey = $new->getShortTableName() . '_id';
        $existingFields = [];

        foreach ($db->fetchAll(
            $db->select()->from($table)->where("$objectKey = ?", $objectId)
        ) as $mapping) {
            $existingFields[(int) $mapping->datafield_id] = $mapping;
        }
        foreach ($object->fields as $field) {
            $id = $fieldMap[(int) $field->datafield_id];
            if (isset($existingFields[$id])) {
                unset($existingFields[$id]);
            } else {
                $db->insert($table, [
                    $objectKey     => $objectId,
                    'datafield_id' => $id,
                    'is_required'  => $field->is_required,
                    'var_filter'   => $field->var_filter,
                ]);
            }
        }
        if (! empty($existingFields)) {
            $db->delete(
                $table,
                $db->quoteInto(
                    "$objectKey = $objectId AND datafield_id IN (?)",
                    array_keys($existingFields)
                )
            );
        }
    }

    /**
     * @param IcingaObject $object
     * @param $list
     * @throws \Icinga\Module\Director\Exception\DuplicateKeyException
     */
    protected function recursivelyStore(IcingaObject $object, & $list)
    {
        foreach ($object->listImportNames() as $parent) {
            if (array_key_exists($parent, $list)) {
                $this->recursivelyStore($list[$parent], $list);
            }
        }

        $object->store();
    }

    /**
     * @return BasketContent
     * @throws \Icinga\Exception\NotFoundError
     */
    protected function getContent()
    {
        if ($this->content === null) {
            $this->content = BasketContent::load($this->get('content_checksum'), $this->getConnection());
        }

        return $this->content;
    }

    protected function onDelete()
    {
        $db = $this->getDb();
        $db->delete(
            ['bc' => 'director_basket_content'],
            'NOT EXISTS (SELECT director_basket_checksum WHERE content_checksum = bc.checksum)'
        );
    }

    /**
     * @return string
     * @throws \Icinga\Exception\NotFoundError
     */
    public function getJsonSummary()
    {
        if ($this->hasBeenLoadedFromDb()) {
            return $this->getContent()->get('summary');
        } else {
            return Json::encode($this->getSummary(), JSON_PRETTY_PRINT);
        }
    }

    /**
     * @return array|mixed
     * @throws \Icinga\Exception\NotFoundError
     */
    public function getSummary()
    {
        if ($this->hasBeenLoadedFromDb()) {
            return Json::decode($this->getContent()->get('summary'));
        } else {
            $summary = [];
            foreach (array_keys($this->objects) as $key) {
                $summary[$key] = count($this->objects[$key]);
            }

            return $summary;
        }
    }

    /**
     * @return string
     * @throws \Icinga\Exception\NotFoundError
     */
    public function getJsonDump()
    {
        if ($this->hasBeenLoadedFromDb()) {
            return $this->getContent()->get('content');
        } else {
            return Json::encode($this->objects, JSON_PRETTY_PRINT);
        }
    }

    protected function addAll($typeName)
    {
        $class = static::getClassForType($typeName);
        /** @var IcingaObject $dummy */
        $dummy = $class::create();
        /** @var ExportInterface $object */
        if ($dummy instanceof IcingaObject && $dummy->supportsImports()) {
            $db = $this->getDb();
            if ($dummy instanceof IcingaCommand) {
                $select = $db->select()->from($dummy->getTableName())
                    ->where('object_type != ?', 'external_object');
            } else {
                $select = $db->select()->from($dummy->getTableName())
                    ->where('object_type = ?', 'template');
            }
            $all = $class::loadAll($this->getConnection(), $select);
        } else {
            $all = $class::loadAll($this->getConnection());
        }
        foreach ($all as $object) {
            $this->objects[$typeName][$object->getUniqueIdentifier()] = $object->export();
        }
    }

    protected function addByIdentifiers($typeName, $identifiers)
    {
        foreach ($identifiers as $identifier) {
            $this->addByIdentifier($typeName, $identifier);
        }
    }

    /**
     * @param $typeName
     * @param $identifier
     * @param Db $connection
     * @return ExportInterface|null
     */
    public static function instanceByIdentifier($typeName, $identifier, Db $connection)
    {
        $class = static::getClassForType($typeName);
        if (substr($class, -13) === 'IcingaService') {
            $identifier = [
                'object_type' => 'template',
                'object_name' => $identifier,
            ];
        }
        /** @var ExportInterface $object */
        if ($class::exists($identifier, $connection)) {
            $object = $class::load($identifier, $connection);
        } else {
            $object = null;
        }

        return $object;
    }

    /**
     * @param $typeName
     * @param $identifier
     */
    protected function addByIdentifier($typeName, $identifier)
    {
        /** @var Db $connection */
        $connection = $this->getConnection();
        $object = static::instanceByIdentifier(
            $typeName,
            $identifier,
            $connection
        );
        $this->objects[$typeName][$identifier] = $object->export();
    }
}
