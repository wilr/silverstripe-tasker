<?php

namespace Wilr\SilverStripe\Tasker\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;

class ElementalMigrationCheck extends BuildTask
{
    public function run($request)
    {
        $pageId = $request->getVar('pageId');

        $page = Page::get()->byId($pageId);

        printf('Page: %s (%s)', $page->Title, $page->ClassName);
        echo PHP_EOL;
        printf('Published: %s', ($page->isPublished()) ? 'Yes' : 'No');
        echo PHP_EOL;
        printf('Elemental Area ID: %s', $page->ElementalAreaID);
        echo PHP_EOL;
        printf('Published: %s', ($page->ElementalArea()->isPublished()) ? 'Yes' : 'No');
        echo PHP_EOL;
        $draft = DB::query('SELECT COUNT(*) FROM Element WHERE ParentID = '. $page->ElementalAreaID)->value();
        printf('Elements (Draft) %s', $draft);
        echo PHP_EOL;
        $live = DB::query('SELECT COUNT(*) FROM Element_Live WHERE ParentID = '. $page->ElementalAreaID)->value();
        printf('Elements (Live) %s', $live);
    }
}
