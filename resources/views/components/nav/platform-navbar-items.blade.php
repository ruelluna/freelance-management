@php
    $user = auth()->user();
    $team = $user->currentTeam;
    $isScopedUser = $team !== null && $user->isScopedTeamUser($team);
    $isClient = $team !== null && $user->isTeamClient($team);
    $canManageClients = $team !== null && $user->hasTeamPermission($team, \App\Enums\TeamPermission::ManageClients);
    $canManageUsers = $team !== null && $user->hasTeamPermission($team, \App\Enums\TeamPermission::ManageUsers);
    $onClientDashboard = $user->homeRoute() === 'dashboard';
@endphp

<x-link :href="route($user->homeRoute())" :text="__('Dashboard')" icon="home" navigate :color="($onClientDashboard ? request()->routeIs('dashboard') : request()->routeIs('workspace')) ? 'primary' : 'gray'" />
<x-link :href="route($user->sectionRoute('projects.index'))" :text="__('Projects')" icon="folder" navigate :color="request()->routeIs('projects.*', 'client.projects.*') ? 'primary' : 'gray'" />
<x-link :href="route($user->sectionRoute('issues.index'))" :text="__('Tasks')" icon="queue-list" navigate :color="request()->routeIs('issues.*', 'client.issues.*') ? 'primary' : 'gray'" />

@if ($isClient)
    <x-link :href="route($user->sectionRoute('users.index'))" :text="__('Users')" icon="users" navigate :color="request()->routeIs('client.users.*') ? 'primary' : 'gray'" />
@endif

@if (! $isScopedUser)
    @if ($canManageUsers)
        <x-link :href="route('users.index')" :text="__('Users')" icon="users" navigate :color="request()->routeIs('users.*') ? 'primary' : 'gray'" />
    @endif
    @if ($canManageClients)
        <x-link :href="route($user->sectionRoute('clients.index'))" :text="__('Clients')" icon="building-office" navigate :color="request()->routeIs('clients.*', 'client.clients.*') ? 'primary' : 'gray'" />
    @endif
    <x-link :href="route($user->sectionRoute('labels.index'))" :text="__('Labels')" icon="tag" navigate :color="request()->routeIs('labels.*', 'client.labels.*') ? 'primary' : 'gray'" />
@endif
