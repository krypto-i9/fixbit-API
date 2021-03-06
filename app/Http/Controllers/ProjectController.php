<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\ProjectUserSearch;
use App\Models\Member;

class ProjectController extends Controller
{
    private $status_ok = 200;
    private $status_created = 201;
    private $status_accepted = 202;
    private $status_badrequest = 400;
    private $status_unauthorized = 401;
    private $status_forbidden = 403;
    private $status_notfound = 404;

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if(!is_null($user)){
            $project_ids = [];

            if($request->filter === "my")
                $project_ids = ProjectUserSearch::where('uid', $user->id)->distinct()->get('pid');
            else if($request->filter === "public")
                $project_ids = ProjectUserSearch::where('is_public', true)->distinct()->get('pid');
            else if($request->filter === "private")
                $project_ids = ProjectUserSearch::where('uid', $user->id)->where('is_public', false)->distinct()->get('pid');
            else
                $project_ids = ProjectUserSearch::where('uid', $user->id)->orWhere('is_public', true)->distinct()->get('pid');

            if(count($project_ids) >= 0){
                $projects = [];
                $data = [];
                $per_page = is_numeric($request->per_page) && $request->per_page > 0 && $request->per_page == round($request->per_page, 0) ? $request->per_page : 20;

                if($request->search !== null){
                    $projects = Project::whereIn('id', $project_ids)->where('name', 'LIKE', "%$request->search%")->latest()->paginate($per_page);
                }else{
                    if($request->sort === "name_asc"){
                        $projects = Project::whereIn('id', $project_ids)->orderBy('name', 'asc')->paginate($per_page);
                    }else if($request->sort === "name_desc")
                    $projects = Project::whereIn('id', $project_ids)->orderBy('name', 'desc')->paginate($per_page);
                    else if($request->sort === "latest")
                    $projects = Project::whereIn('id', $project_ids)->latest()->paginate($per_page);
                    else if($request->sort === "oldest")
                    $projects = Project::whereIn('id', $project_ids)->oldest()->paginate($per_page);
                    else if($request->sort === "pid_desc")
                    $projects = Project::whereIn('id', $project_ids)->orderBy('id', 'desc')->paginate($per_page);
                    else
                    $projects = Project::whereIn('id', $project_ids)->paginate($per_page);
                }

                foreach($projects as $project){
                    $team_data = null;
                    $member_data = null;
                    if(!is_null($project->team_id)){
                        $team_data = Team::find($project->team_id);
                        $member_data = DB::table("team_".$project->team_id)->get();
                    }
                    $admin = User::find($project->admin_id);
                    $issue_total_count = count(DB::table("project_".$project->id)->get());
                    $issue_open_count = count(DB::table("project_".$project->id)->where("is_open", true)->get());
                    $data[] = array(
                        'project' => $project,
                        'team' => array('info' => $team_data, 'members' => $member_data),
                        'admin' => $admin,
                        'issue' => array(
                            'total' => $issue_total_count,
                            'open'  => $issue_open_count,
                        ),
                    );
                }
                return response()->json([
                    "success" => true,
                    "type"    => "info",
                    "reason"  => null,
                    "msg"     => "Projects fetched successfully",
                    "data"    => array(
                                    'data' => $data,
                                    'pagination' => array(
                                        'total'        => $projects->total(),
                                        'per_page'     => $projects->perPage(),
                                        'page' => $projects->currentPage(),
                                        'count'        => $projects->count()
                                    )
                                )], $this->status_ok);
            }else{
                return response()->json([
                    "success" => false,
                    "type"    => "error",
                    "reason"  => "notfound",
                    "msg"     => "Project not found",
                    "data"    => null], $this->status_notfound);
            }
        }else{
            return response()->json([
                "success" => false,
                "type"    => "error",
                "reason"  => "unauthorized",
                "msg"     => "Unauthorized",
                "data"    => null], $this->status_unauthorized);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        if(!is_null($user)){
            $validator = Validator::make($request->all(), [
                "name"        => "required|unique:projects|max:30",
                "description" => "required|max:500",
                "is_public"   => "required|boolean",
                "team_id"     => "integer|nullable"
                ]);

            if($validator->fails()){
                return response()->json([
                    "success" => false,
                    "type"    => "error",
                    "reason"  => "validation error",
                    "msg"     => $validator->errors(),
                    "data"    => null
                ], $this->status_badrequest);
            }

            $project_data = array(
                "name"        =>$request->name,
                "description" => $request->description,
                "is_public"   => $request->is_public,
                "creator_id"  => $user->id,
                "admin_id"    => $user->id,
                "team_id"     => $request->team_id,
            );

            $project = Project::create($project_data);
            $member_cls = new Member();
            if(!is_null($request->team_id)){
                $members = $member_cls->getTeamMembers($project->team_id);
                foreach($members as $member){
                    $pu_data = array(
                        'pid'        => $project->id,
                        'uid'        => $member->uid,
                        'is_public'  => $project->is_public,
                        'created_at' => $member_cls->freshTimestamp(),
                        'updated_at' => $member_cls->freshTimestamp(),
                    );
                    if($member->uid !== $project->admin_id){
                        ProjectUserSearch::insertOrIgnore($pu_data);
                    }
                }
            }

            if(is_null($project)){
                return response()->json([
                    "success" => false,
                    "type"    => "error",
                    "reason"  => "not create",
                    "msg"     => "Project create failed",
                    "data"    => null
                ], $this->status_forbidden);
            }

            $proj = new Project();
            $proj->createIssueTable($project->id);

            $pu_data = array(
                "pid"       => $project->id,
                "uid"       => $user->id,
                "is_public" => $project->is_public
            );

            $pu_search = ProjectUserSearch::create($pu_data);

            if(!is_null($pu_search)){
                return response()->json([
                    "success" => true,
                    "type"    => "success",
                    "reason"  => null,
                    "msg"     => "Project created successfully",
                    "data"    => $project
                ], $this->status_created);
            }else{
                return response()->json([
                    "success" => false,
                    "type"    => "error",
                    "reason"  => "unknown",
                    "msg"     => "Something went wrong, please contact support",
                    "data"    => null
                ], $this->status_forbidden);
            }
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $user = Auth::user();
        if(!is_null($user)){
            $pu = new ProjectUserSearch();
            $pu_ids = $pu->projectIdsUserCanAccess($id, $user->id);
            if(count($pu_ids) === 1 && $project = Project::find($pu_ids[0]->pid)){
                $team_data = null;
                $member_data = null;
                if($project->team_id !== null) {
                    $team_data = Team::find($project->team_id);
                    $member_data = DB::table("team_".$project->team_id)->get();
                }
                $admin = User::find($project->admin_id);
                $data = array(
                    'project' => $project,
                    'team' => array(
                        'info' => $team_data,
                        'members' => $member_data
                    ), 'admin' => $admin);
                return response()->json([
                    "success" => true,
                    "type"    => "info",
                    "reason"  => null,
                    "msg"     => "Project fetched successfully",
                    "data"    => $data], $this->status_ok);
            }else{
                return response()->json([
                    "success" => false,
                    "type"    => "error",
                    "reason"  => "not fetched",
                    "msg"     => "No such project to view",
                    "data"    => null], $this->status_notfound);
            }
        }else{
            return response()->json([
                "success" => false,
                "type"    => "error",
                "reason"  => "unauthorized",
                "msg"     => "Unauthorized",
                "data"    => null], $this->status_unauthorized);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $project= Project::find($id);
        $old_team_id = $project->team_id;
        if(!is_null($project)){
            if(!is_null($user) && ($project->admin_id === $user->id)){
                $validator = Validator::make($request->all(), [
                    "name" => "unique:projects|max:30",
                    "description" => "max:500",
                    "is_public" => "boolean",
                    "admin_id"  => "integer",
                    "team_id"   => "integer|nullable"
                ]);

                if($validator->fails()){
                    return response()->json([
                    "success" => false,
                    "type"    => "error",
                    "reason"  => "validation error",
                    "msg"     => $validator->errors(),
                    "data"    => null], $this->status_badrequest);
                }

                if(!is_null($project) && $project->update($request->all()) === true){
                    $member_cls = new Member();
                    if(!is_null($request->team_id)){
                        if(!is_null($old_team_id) && $old_team_id !== $project->team_id){
                            $old_members = $member_cls->getTeamMembers($old_team_id);
                            foreach($old_members as $old_member){
                                if($old_member->uid !== $project->admin_id){
                                    ProjectUserSearch::where('pid', $id)->where('uid', $old_member->uid)->delete();
                                }
                            }
                        }

                        $members = $member_cls->getTeamMembers($project->team_id);
                        foreach($members as $member){
                            $pu_data = array(
                                'pid'        => $project->id,
                                'uid'        => $member->uid,
                                'is_public'  => $project->is_public,
                                'created_at' => $member_cls->freshTimestamp(),
                                'updated_at' => $member_cls->freshTimestamp(),
                            );
                            ProjectUserSearch::insertOrIgnore($pu_data);
                        }
                    }else{
                        if(!is_null($old_team_id) && $old_team_id !== $project->team_id){
                            $old_members = $member_cls->getTeamMembers($old_team_id);
                            foreach($old_members as $old_member){
                                if($old_member->uid !== $project->admin_id){
                                    ProjectUserSearch::where('pid', $id)->where('uid', $old_member->uid)->delete();
                                }
                            }
                        }
                    }
                    if(!is_null($project->is_public)){
                        ProjectUserSearch::where('pid', $project->id)->update(['is_public' => $project->is_public]);
                    }
                    return response()->json([
                        "success" => true,
                        "type"    => "success",
                        "reason"  => null,
                        "msg"     => "Project updated successfully",
                        "data"    => $project], $this->status_ok);
                }else{
                    return response()->json([
                        "success" => false,
                        "type"    => "error",
                        "reason"  => "not updated",
                        "msg"     => "No such project to update",
                        "data"    => null], $this->status_badrequest);
                }
            }else{
                return response()->json([
                    "success" => false,
                    "type"    => "error",
                    "reason"  => "unauthorized",
                    "msg"     => "Unauthorized",
                    "data"    => null], $this->status_unauthorized);
            }
        }else{
            return response()->json([
                "success" => false,
                "type"    => "error",
                "reason"  => "notfound",
                "msg"     => "No such project to update",
                "data"    => null], $this->status_notfound);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $user = Auth::user();
        if(!is_null($user)){
            $project = Project::find($id);
            if(!is_null($project) && ($project->admin_id === $user->id) && ($project->delete() === true)){
                $proj = new Project();
                $proj->dropIssueTable($id);
                return response()->json([
                    "success" => true,
                    "type"    => "success",
                    "reason"  => null,
                    "msg"     => "Project deleted successfully",
                    "data"    => null], $this->status_ok);
            }else{
                return response()->json([
                    "success" => false,
                    "type"    => "error",
                    "reason"  => "notfound",
                    "msg"     => "No such project to delete",
                    "data"    => null], $this->status_notfound);
            }
        }
        return response()->json([
            "success" => false,
            "type"    => "error",
            "reason"  => "unauthorized",
            "msg"     => "Unauthorized",
            "data"    => null], $this->status_unauthorized);
    }
}
