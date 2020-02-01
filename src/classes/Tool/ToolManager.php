<?php

namespace Api\Tool;

use Api\Inventory\Inventory;
use Api\Inventory\SnipeitInventory;
use Api\Model\Tool;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;

class ToolManager
{
    public static function instance($logger = null) {
        return new ToolManager(SnipeitInventory::instance(), $logger);
    }
    private $inventory;
    private $logger;

    /**
     * ToolManager constructor.
     */
    public function __construct(Inventory $inventory, $logger)
    {
        $this->inventory = $inventory;
        $this->logger = $logger;
    }

    public function getAll($showAll = false, $category = null, $sortfield = "code", $sortdir = "asc",
        $page=1, $perPage = 1000) {
        $tools = $this->getAllFromInventory($showAll, $category, $sortfield, $sortdir, $page, $perPage);
//        $tools = $this->getAllFromDatabase($showAll, $category, $sortfield, $sortdir);
        return $tools;
    }
    public function toolExists($toolId) : bool
    {
        return $this->inventory->toolExists($toolId);
    }
    public function getById($id) {
        $tool = $this->getByIdFromInventory($id);
//        $tool = \Api\Model\Tool::find($id);

        // TODO: create or update corresponding tool in local db??
        // needed to store mutliple images and handle reservations
        return $tool;
    }

    protected function getByIdFromInventory($id) {
        return $this->inventory->getToolById($id);
    }

//    protected function getByIdFromInventory($id) {
//        $tool = \Api\Model\Tool::find($id);
//        $this->logger->info(json_encode($tool));
//        if (isset($tool->tool_ext_id)) {
//            return $this->inventory->getToolById($tool->tool_ext_id);
//        } else {
//            $inventoryTool = $this->inventory->getToolByCode($tool->code);
//            $tool->tool_ext_id = $inventoryTool->tool_ext_id;
//            return $inventoryTool;
//        }
//
//    }
    /**
     * @param $showAll
     * @param $categoryFilter
     * @param $sortfield
     * @param $sortdir
     * @return mixed
     */
    protected function getAllFromInventory($showAll, $categoryFilter, $sortfield = 'code', $sortdir = 'asc', $page, $perPage)
    {
        $tools = new Collection();
        $inventoryTools = $this->inventory->getTools();
        // only apply pagination if filter can be applied directly to assets
//        $assets = $this->inventory->getAssets(($page - 1) * $perPage, $perPage);

        foreach ($inventoryTools as $tool) {
            if ( ($this->isVisible($showAll, $tool))
                && $this->applyCategoryFilter($categoryFilter, $tool)) {
                $tools->add($tool);
            }
        }
        if ($sortdir == 'desc') {
            return $tools->sortByDesc($sortfield);
        } else {
            return $tools->sortBy($sortfield);
        }
    }
    /**
     * @param $showAll
     * @param $category
     * @param $sortfield
     * @param $sortdir
     * @return mixed
     */
    protected function getAllFromDatabase($showAll, $category, $sortfield, $sortdir)
    {
        if ($showAll === true) {
            $builder = Capsule::table('tools');
        } else {
            $builder = Capsule::table('tools')
                ->where('visible', true);
        }
        if (isset($category)) {
            $builder = $builder->where('category', $category);
        }
        $tools = $builder->orderBy($sortfield, $sortdir)->get();
        return $tools;
    }

    /**
     * @param $showAll
     * @param $tool
     * @return bool
     */
    protected function isVisible($showAll, $tool): bool
    {
        return $showAll || $tool->visible == TRUE;
    }

    /**
     * Returns true if the tool belongs to the category or if no category filter should be applied
     * @param $categoryFilter
     * @param $tool
     * @return bool
     */
    protected function applyCategoryFilter($categoryFilter, $tool): bool
    {
        if (!isset($categoryFilter) || empty($categoryFilter)) {
            return TRUE;
        }
        return $this->isInCategory($categoryFilter, $tool);
    }
    protected function isInCategory($category, $tool) {
        if (!isset($category) || empty($category)) {
            return false;
        }
        return $tool->category == $category;
    }
}