<?php

namespace Wilr\SilverStripe\Tasker\Extensions;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\FieldList;

class TaskerSiteConfigExtension extends DataExtension
{
    private static $db = [
        'SchemaVersion' => 'Int'
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName('SchemaVersion');
    }
}
