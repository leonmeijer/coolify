<?php

namespace App\Livewire\Server;

use App\Actions\Server\DeleteServer;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public ?Collection $servers = null;

    public function mount()
    {
        $this->servers = Server::ownedByCurrentTeamCached();
    }

    public function delete(int $serverId, $password)
    {
        if (! verifyPasswordConfirmation($password, $this)) {
            return;
        }

        try {
            $server = Server::ownedByCurrentTeam()->findOrFail($serverId);
            $this->authorize('delete', $server);

            if ($server->hasDefinedResources()) {
                $this->dispatch('error', 'Server has defined resources. Please delete them first.');

                return;
            }

            $server->delete();
            DeleteServer::dispatch(
                $server->id,
                false, // delete_from_hetzner
                $server->hetzner_server_id,
                $server->cloud_provider_token_id,
                $server->team_id
            );

            $this->servers = Server::ownedByCurrentTeamCached();
            $this->dispatch('success', 'Server deleted successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.index');
    }
}
