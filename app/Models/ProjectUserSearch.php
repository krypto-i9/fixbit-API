<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectUserSearch extends Model
{
    use HasFactory;

    protected $fillable = [
        "pid",
        "uid",
        "is_public",
    ];

    protected $casts = [
        'pid' => 'integer',
        'uid' => 'integer',
        'is_public' => 'boolean',
    ];

    /**
     * Project ids that user can access
     *
     * @param int pid
     * @param int uid
     * @return array
     */
    public function projectIdsUserCanAccess(int $pid,int $uid){
        $pu = $this->where('pid', $pid)->where('is_public', true)->orWhere('pid', $pid)->where('uid', $uid)->distinct()->get('pid');
        return $pu;
    }

     /**
     * Check user has access to project
     *
     * @param int pid
     * @param int uid
     * @return boolean
     */
    public function isUserHasAccessToProject(int $pid,int $uid){
        $pu = $this->projectIdsUserCanAccess($pid, $uid);
        if(count($pu) === 1){
            return true;
        }
        return false;
    }

}
