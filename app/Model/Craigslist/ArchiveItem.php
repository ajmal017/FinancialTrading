<?php

namespace App\Model\Craigslist;

use Illuminate\Database\Eloquent\Model;

class ArchiveItem extends Model {
    protected $connection = 'craigslist';
    protected $table = 'archive_items';
}