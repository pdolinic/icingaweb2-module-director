<?php

namespace Icinga\Module\Director\Web\Table;

use ipl\Html\Html;
use gipfl\IcingaWeb2\Table\Extension\MultiSelect;
use gipfl\IcingaWeb2\Link;
use gipfl\IcingaWeb2\Url;

class ObjectsTableService extends ObjectsTable
{
    use MultiSelect;

    protected $type = 'service';

    protected $searchColumns = [
        'o.object_name',
        'h.object_name'
    ];

    public function getColumns()
    {
        return [
            'object_name'      => 'o.object_name',
            'disabled'         => 'o.disabled',
            'host_set'         => 'CASE WHEN o.service_set_id IS NULL THEN h.object_name ELSE os.object_name END',
            'host_object_type' => 'h.object_type',
            'host_disabled'    => 'h.disabled',
            'id'               => 'o.id',
            'blacklisted'      => "CASE WHEN hsb.service_id IS NULL THEN 'n' ELSE 'y' END",
        ];
    }

    public function assemble()
    {
        $this->enableMultiSelect(
            'director/services/edit',
            'director/services',
            ['id']
        );
    }

    public function getColumnsToBeRendered()
    {
        return [
            'host_set'        => 'Host | Service Set',
            'object_name' => 'Service Name'
        ];
    }

    public function renderRow($row)
    {
        $url = Url::fromPath('director/service/edit', [
            'name' => $row->object_name,
            'host_set' => $row->host_set,
            'id'   => $row->id,
        ]);

        $caption = $row->host_set === null
            ? Html::tag('span', ['class' => 'error'], '- none -')
            : $row->host_set;

        $hostField = static::td(Link::create($caption, $url));
        if ($row->host_set === null) {
            $hostField->getAttributes()->add('class', 'error');
        }
        $tr = static::tr([
            $hostField,
            static::td($row->object_name)
        ]);

        $attributes = $tr->getAttributes();
        if ($row->host_disabled === 'y' || $row->disabled === 'y') {
            $attributes->add('class', 'disabled');
        }
        if ($row->blacklisted === 'y') {
            $attributes->add('class', 'strike-links');
        }

        return $tr;
    }

    public function prepareQuery()
    {
        return parent::prepareQuery()->joinLeft(
            ['h' => 'icinga_host'],
            'o.host_id = h.id',
            []
        )->joinLeft(
            ['hsb' => 'icinga_host_service_blacklist'],
            'hsb.service_id = o.id AND hsb.host_id = o.host_id',
            []
        )->joinLeft(
            ['os' => 'icinga_service_set'],
            'os.id = o.service_set_id',
            []
        )->order('o.object_name')->order('h.object_name');
    }
}
