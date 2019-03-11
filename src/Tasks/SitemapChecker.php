<?php

namespace Wilr\SilverStripe\Tasker\Tasks;

use Wilr\SilverStripeTasker\Traits\TaskerFormatter;
use SilverStripe\Dev\BuildTask;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Versioned\Versioned;

class SitemapChecker extends BuildTask
{
    use TaskerFormatter;

    protected $checkAdmin = true;

    public function run($request)
    {
        $pages = Versioned::get_by_stage(SiteTree::class, Versioned::LIVE);
        $total = $pages->count();
        $i = 0;
        foreach ($pages as $page) {
            $i++;

            try {
                $result = Director::test($page->Link());
                $count = "($i/$total)";

                if ($result->getStatusCode() === 500) {
                    $this->echoWarning($page->Link() . ' 500 Server Error '. $count);
                } else {
                    $this->echoTick($page->Link() . ' '. $result->getStatusCode() . $count);
                }

                if ($this->checkAdmin) {
                    $result = Director::test($page->CMSEditLink());

                    if ($result->getStatusCode() === 500) {
                        $this->echoError(
                            $page->CMSEditLink() . ' CMS 500 Server Error'
                        );
                    } else {
                        $this->echoSuccess(
                            $page->CMSEditLink() . ' '. $result->getStatusCode()
                        );
                    }
                } else {
                }
            } catch (Exception $e) {
                $this->echoWarning($page->Link() . ' 500 Server Error '. $count);
                $this->echoWarning($e->getMessage());
            }
        }
    }
}
