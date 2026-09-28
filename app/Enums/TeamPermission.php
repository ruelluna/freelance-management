<?php

namespace App\Enums;

enum TeamPermission: string
{
    case UpdateTeam = 'team:update';
    case DeleteTeam = 'team:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    case ManageConnections = 'connection:manage';
    case ManageLabels = 'label:manage';
    case ManageProjects = 'project:manage';
    case ManageClients = 'client:manage';
    case ManageUsers = 'user:manage';
    case ViewIssues = 'issue:view';
    case CreateIssues = 'issue:create';
    case UpdateIssues = 'issue:update';
    case CommentOnIssues = 'issue:comment';
    case AssignIssues = 'issue:assign';
}
