<?php
namespace App\Areas\Menu\Models;

use ManaPHP\Db\Model;

class Group extends Model
{
    public $group_id;
    public $group_name;
    public $display_order;
    public $creator_name;
    public $updator_name;
    public $created_time;
    public $updated_time;

    public function getSource($context = null)
    {
        return 'menu_group';
    }

    public function rules()
    {
        return [
            'group_name' => 'unique',
            'display_order' => ['range' => '0-127']
        ];
    }
}