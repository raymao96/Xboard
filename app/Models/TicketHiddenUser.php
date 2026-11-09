<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketHiddenUser extends Model
{
    protected $table = 'v2_ticket_hidden_user';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['user_id'];
}
