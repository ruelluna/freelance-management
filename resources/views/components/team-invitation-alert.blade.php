@props([
    'invitation',
    'action',
])

<div data-test="team-invitation-alert">
    <x-alert
        color="blue"
        light
        icon="information-circle"
        :text="__(':action to join the \":team\" team.', ['action' => $action, 'team' => $invitation['teamName']])"
    />
</div>
