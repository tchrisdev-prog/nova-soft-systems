<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use App\Models\Client;

class UpdateClientRequest extends ClientRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->currentClient());
    }

    protected function currentClient(): ?Client
    {
        return $this->route('client');
    }
}
