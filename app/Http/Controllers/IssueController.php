<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Issue;
use App\Models\ProjectUserSearch;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Events\CommentNotifyEvent;
use App\Events\IssueAssignNotifyEvent;

class IssueController extends Controller
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
     * @param int pid
     * @return \Illuminate\Http\Response
     */
    public function index($pid)
    {
        $user = Auth::user();
        if(!is_null($user)){
            $pu = new ProjectUserSearch();
            $user_has_access = $pu->isUserHasAccessToProject($pid, $user->id);
            if($user_has_access){
                $issue = new Issue();
                $issues = $issue->getAllIssues($pid);
                return response()->json([
                "success" => true,
                "type"    => "info",
                "reason"  => null,
                "msg"     => "Issues fetched successfully",
                "data"    => $issues], $this->status_ok);
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
    public function store(Request $request,int $pid)
    {
        $user = Auth::user();
        if(!is_null($user)){
            $pu = new ProjectUserSearch();
            $user_has_access = $pu->isUserHasAccessToProject($pid, $user->id);
            if($user_has_access){
                $validator = Validator::make($request->all(), [
                    'title' => 'required|max:30',
                    'description' => 'required|max:5000',
                    'attachments' => 'file',
                    'assign_to' => 'integer|nullable',
                    'priority'    => 'required|integer',
                    'type'        => 'required|integer',
                    'is_open'     => 'required|boolean'
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

                $issue = new Issue();
                $data = array(
                    "title"       => $request->title,
                    "description" => $request->description,
                    "attachments" => $request->attachments,
                    "assign_to" => $request->assign_to,
                    "creator_id"  => $user->id,
                    "priority"    => $request->priority,
                    "type"        => $request->type,
                    "is_open"     => $request->is_open,
                    "created_at" => $issue->freshTimestamp(),
                    "updated_at" => $issue->freshTimestamp()
                );
                $is_iid = $issue->createIssue($pid, $data);
                if(!is_null($is_iid)){
                    if($request->assign_to !== NULL && (boolean) $request->is_open === true && $user->id !== $request->assign_to){
                        event(new IssueAssignNotifyEvent($request->assign_to, $request->priority, $user->username,  $pid, $is_iid));
                     }
                    return response()->json([
                        "success" => true,
                        "type"    => "success",
                        "reason"  => null,
                        "msg"     => "Issue created successfully",
                        "data"    => null
                    ], $this->status_created);
                }else{
                    return response()->json([
                        "success" => false,
                        "type"    => "error",
                        "reason"  => "unknown",
                        "msg"     => "Something went wrong, please contact support",
                        "data"    => null
                    ], $this->status_badrequest);
                }
            }else{
                return response()->json([
                    "success" => false,
                    "type"    => "error",
                    "reason"  => "notfound",
                    "msg"     => "Project not found",
                    "data"    => null
                ], $this->status_notfound);
            }
        }
        return response()->json([
            "success" => false,
            "type"    => "error",
            "reason"  => "unathorized",
            "msg"     => "Unathorized",
            "data"    => null
        ], $this->status_unauthorized);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $pid
     * @param  int  $iid
     * @return \Illuminate\Http\Response
     */
    public function show($pid, $iid)
    {
        $user = Auth::user();
        if(!is_null($user)){
            $pu = new ProjectUserSearch();
            $user_has_access = $pu->isUserHasAccessToProject($pid, $user->id);
            if($user_has_access){
                $issue = new Issue();
                $issue_data = $issue->getIssue($pid, $iid);
                if(!is_null($issue_data[0]->comments))
                    $issue_data[0]->comments = \json_decode($issue_data[0]->comments);
                if(count($issue_data) === 1){
                    $project = Project::find($pid);
                    $team_data = null;
                    $member_data = null;
                    if($project->team_id !== null) {
                        $team_data = Team::find($project->team_id);
                        $member_data = DB::table("team_".$project->team_id)->get(['uid', 'name']);
                    }
                    $admin = User::find($project->admin_id)->only(['id', 'username']);
                    $data = array(
                        'issue' => $issue_data[0],
                        'team' => array(
                            'info' => $team_data,
                            'members' => $member_data
                        ), 'admin' => $admin);
                    return response()->json([
                        "success" => true,
                        "type"    => "info",
                        "reason"  => null,
                        "msg"     => "Issue data fetched successfully",
                        "data"    => $data
                    ], $this->status_ok);
                }
            }
            return response()->json([
                "success" => false,
                "type"    => "error",
                "reason"  => "notfound",
                "msg"     => "Issue not found",
                "data"    => null
            ], $this->status_notfound);

        }
        return response()->json([
            "success" => false,
            "type"    => "error",
            "reason"  => "unathorized",
            "msg"     => "Unathorized",
            "data"    => null
        ], $this->status_unauthorized);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $pid
     * @param  int  $iid
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $pid, $iid)
    {
        $user = Auth::user();
        if(!is_null($user)){
            $issue = new Issue();
            if(count($request->input()) === 2 && $request->commentAction === true){
                $updated = $issue->updateIssueByColumn($pid, $iid, 'comments', $request->comments);
                if($updated){
                    return response()->json([
                        "success" => true,
                        "type"    => "success",
                        "reason"  => null,
                        "msg"     => "Comments updated",
                        "data"    => null
                    ], $this->status_ok);
                }else{
                    return response()->json([
                        "success" => false,
                        "type"    => "error",
                        "reason"  => "unknown",
                        "msg"     => "Something went wrong, please contact support",
                        "data"    => null
                    ], $this->status_badrequest);
                }
            }
            if(count($request->input()) === 1 && $request->comments !== null){
                $updated = $issue->updateCommentsColumn($pid, $iid, $request->comments, $issue->freshTimeStamp());
                if($updated) {
                    $issue_data = $issue->getIssue($pid, $iid);
                    event(new CommentNotifyEvent(is_null($issue_data[0]->assign_to) ? 0 : $issue_data[0]->assign_to, $request->comments, $pid, $iid));
                    return response()->json([
                        "success" => true,
                        "type"    => "success",
                        "reason"  => null,
                        "msg"     => "Comment Added",
                        "data"    => null
                    ], $this->status_ok);
                }else{
                    return response()->json([
                        "success" => false,
                        "type"    => "error",
                        "reason"  => null,
                        "msg"     => "Comment not created",
                        "data"    => null
                    ], $this->status_badrequest);
                }
            }
            $user_has_access = $issue->isUserHasAccessToIssue($pid, $user->id, $iid);
            if($user_has_access){
                $validator = Validator::make($request->all(), [
                    'title' => 'max:30',
                    'description' => 'max:5000',
                    'attachments' => 'file',
                    'assign_to'   => 'integer|nullable',
                    'priority'    => 'integer',
                    'type'        => 'integer',
                    'is_open'     => 'boolean'
                ]);

                if($validator->fails()){
                    return response()->json([
                        "success" => false,
                        "type"    => "error",
                        "reason"  => "validation error",
                        "msg"     => $validator->errors(),
                        "data"    => null], $this->status_badrequest);
                }

                if(count($request->input()) === 1 && $request->is_open !== null){
                    $issue->updateIssueByColumn($pid, $iid, "updated_at", $issue->freshTimeStamp());
                    $updated = $issue->updateIssueByColumn($pid, $iid, 'is_open', $request->is_open);
                    if($updated){
                        return response()->json([
                            "success" => true,
                            "type"    => "success",
                            "reason"  => null,
                            "msg"     => "Issue updated successfully",
                            "data"    => null
                        ], $this->status_ok);
                    }else{
                        return response()->json([
                            "success" => false,
                            "type"    => "error",
                            "reason"  => "unknown",
                            "msg"     => "Something went wrong, please contact support",
                            "data"    => null], $this->status_badrequest);
                    }
                }else{
                    $prev_issue_data = $issue->getIssue($pid, $iid);
                    $request->request->add(['updated_at' => $issue->freshTimeStamp()]);
                    $updated = $issue->updateIssue($pid, $iid,
                    $request->only([
                        'title','description', 'is_open',
                        'priority', 'type', 'assign_to', 'attachments',
                        'updated_at', 'comments'
                        ])
                    );

                    if($updated){
                        if($request->assign_to !== null &&  $user->id !== $request->assign_to){
                            $issue_data = $issue->getIssue($pid, $iid);
                            if(
                                $issue_data[0]->assign_to !== NULL &&
                                (boolean) $issue_data[0]->is_open === true &&
                                $issue_data[0]->assign_to !== $prev_issue_data[0]->assign_to
                            ){
                               event(new IssueAssignNotifyEvent($issue_data[0]->assign_to,$issue_data[0]->priority, $user->username,  $pid, $iid));
                            }
                        }
                        return response()->json([
                            "success" => true,
                            "type"    => "success",
                            "reason"  => null,
                            "msg"     => "Issue updated successfully",
                            "data"    => null
                        ], $this->status_ok);
                    }else{
                        return response()->json([
                            "success" => false,
                            "type"    => "error",
                            "reason"  => "unknown",
                            "msg"     => "Somtehing went wrong, please contact support",
                            "data"    => null
                        ], $this->status_badrequest);
                    }
                }
            }else{
                return response()->json([
                    "success" => false,
                    "type"    => "error",
                    "reason"  => "forbidden",
                    "msg"     => "Not have access to do the operation. Only creator, team members and administrator has access to do this",
                    "data"    => null
                ], $this->status_forbidden);
            }
        }
        return response()->json([
            "success" => false,
            "type"    => "error",
            "reason"  => "unathorized",
            "msg"     => "Unathorized",
            "data"    => null
        ], $this->status_unauthorized);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $pid
     * @param  int  $iid
     * @return \Illuminate\Http\Response
     */
    public function destroy($pid, $iid)
    {
        $user = Auth::user();
        if(!is_null($user)){
            $issue = new Issue();
            $user_has_access = $issue->isUserHasAccessToIssue($pid, $user->id, $iid);
            if($user_has_access){
                $deleted = $issue->deleteIssue($pid, $iid);
                if($deleted){
                    return response()->json([
                        "success" => true,
                        "type"    => "success",
                        "reason"  => null,
                        "msg"     => "Issue deleted successfully",
                        "data"    => null
                    ], $this->status_ok);
                }else{
                    return response()->json([
                        "success" => false,
                        "type"    => "error",
                        "reason"  => "unknown",
                        "msg"     => "Something went wrong, please contact support",
                        "data"    => null
                    ], $this->status_badrequest);
                }
            }else{
                return response()->json([
                    "success" => false,
                    "type"    => "error",
                    "reason"  => "forbidden",
                    "msg"     => "Not have access to do the operation. Only creator, team members and administrator has access to do this.",
                    "data"    => null
                ], $this->status_forbidden);
            }
        }
        return response()->json([
            "success" => false,
            "type"    => "error",
            "reason"  => "unathorized",
            "msg"     => "Unathorized",
            "data"    => null
        ], $this->status_unauthorized);
    }
}
