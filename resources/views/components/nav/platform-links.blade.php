@php
    $user = auth()->user();
    $team = $user->currentTeam;
    $isScopedUser = $team !== null && $user->isScopedTeamUser($team);
    $isClient = $team !== null && $user->isTeamClient($team);
    $canManageClients = $team !== null && $user->hasTeamPermission($team, \App\Enums\TeamPermission::ManageClients);
    $canManageUsers = $team !== null && $user->hasTeamPermission($team, \App\Enums\TeamPermission::ManageUsers);
    $onClientDashboard = $user->homeRoute() === 'dashboard';
@endphp

<x-side-bar.item
    :text="__('Dashboard')"
    :route="route($user->homeRoute())"
    icon="home"
    :match="$onClientDashboard ? 'dashboard' : 'workspace'"
    :current="$onClientDashboard ? request()->routeIs('dashboard') : request()->routeIs('workspace')"
/>
<x-side-bar.item
    :text="__('Projects')"
    :route="route($user->sectionRoute('projects.index'))"
    icon="folder"
    match="projects.*"
    :current="request()->routeIs('projects.*', 'client.projects.*')"
/>
<x-side-bar.item
    :text="__('Tasks')"
    :route="route($user->sectionRoute('issues.index'))"
    icon="queue-list"
    match="issues.*"
    :current="request()->routeIs('issues.*', 'client.issues.*')"
/>

@if ($isClient)
    <x-side-bar.item
        :text="__('Users')"
        :route="route($user->sectionRoute('users.index'))"
        icon="users"
        match="client.users.*"
        :current="request()->routeIs('client.users.*')"
    />
@endif

@if (! $isScopedUser)
    @if ($canManageUsers)
        <x-side-bar.item
            :text="__('Users')"
            :route="route('users.index')"
            icon="users"
            match="users.*"
            :current="request()->routeIs('users.*')"
        />
    @endif
    @if ($canManageClients)
        <x-side-bar.item
            :text="__('Clients')"
            :route="route($user->sectionRoute('clients.index'))"
            icon="building-office"
            match="clients.*"
            :current="request()->routeIs('clients.*', 'client.clients.*')"
        />
    @endif
    <x-side-bar.item
        :text="__('Labels')"
        :route="route($user->sectionRoute('labels.index'))"
        icon="tag"
        match="labels.*"
        :current="request()->routeIs('labels.*', 'client.labels.*')"
    />
@endif
