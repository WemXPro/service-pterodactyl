<?php

namespace App\Services\Pterodactyl;

use App\Services\ServiceInterface;
use App\Services\Pterodactyl\Entities\Pterodactyl;
use App\Services\Pterodactyl\Entities\Location;
use App\Services\Pterodactyl\Entities\Server;
use Illuminate\Contracts\Container\BindingResolutionException;
use App\Models\Package;
use App\Models\Order;

class Service implements ServiceInterface
{
    private Order $order;

    /**
     * Unique key used to store settings
     * for this service.
     *
     * @return string
     */
    public static $key = 'pterodactyl';

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Returns the meta data about this Server/Service
     *
     * @return object
     */
    public static function metaData(): object
    {
        return (object)
        [
            'display_name' => 'Pterodactyl',
            'autor' => 'WemX',
            'version' => '1.0.0',
            'wemx_version' => ['*'],
        ];
    }

    /**
     * Define the default configuration values required to setup this service
     * i.e host, api key, or other values. Use Laravel validation rules for
     *
     * Laravel validation rules: https://laravel.com/docs/10.x/validation
     *
     * @return array
     */
    public static function setConfig(): array
    {
        return [];
    }

    /**
     * Define the default package configuration values required when creatig
     * new packages. i.e maximum ram usage, allowed databases and backups etc.
     *
     * Laravel validation rules: https://laravel.com/docs/10.x/validation
     *
     * @return array
     */
    public static function setPackageConfig(Package $package): array
    {
        return [];
    }

    /**
     * Define the checkout config that is required at checkout and is fillable by
     * the client. Its important to properly sanatize all inputted data with rules
     *
     * Laravel validation rules: https://laravel.com/docs/10.x/validation
     *
     * @return array
     */
    public static function setCheckoutConfig(Package $package): array
    {
        $locations = Location::query()->whereIn('id', $package->data('locations', []))->get();
        $locations_options = [];
        $memory_limit = $package['data']['memory_limit'];
        $disk_limit = $package['data']['disk_limit'];
        foreach ($locations as $location){
            foreach ($location->nodes()->get() as $node){
                if ($node->checkResource($memory_limit, $disk_limit)){
                    $locations_options[$location->id] = $location->name . " ({$location->inStock()})";
                }
            }
        }

        $variables = collect(json_decode($package->data('egg'))->relationships->variables->data ?? []);
        $variable_forms = $variables->map(function ($variable, int $key) use ($package) {
            $variable = $variable->attributes;

            if(!$variable->user_viewable OR in_array($variable->env_variable, $package->data('excluded_variables', []))) {
                return [];
            }

            // Get the value of the variable
            $variableValue = $package->data("environment")[$variable->env_variable] ?? $variable->default_value ?? '';
            // List of placeholders
            $placeholders = ['AUTO_PORT', 'USERNAME', 'RANDOM_TEXT', 'RANDOM_NUMBER', 'NODE_IP', 'PASSWORD'];
            // Check for placeholder
            if (in_array($variableValue, $placeholders)) {
                // Skip validation for this variable
                return [];
            }

            $rules = explode('|', $variable->rules); // convert into array format
            $type_data = self::determineType($rules);

            $resp = [
                "key" => $variable->env_variable,
                'col' => 'w-1/2 p-2',
                "name" => $variable->name,
                "description" => $variable->description,
                "type" => $type_data['type'],
                "default_value" => $variableValue,
                "rules" => $rules, // laravel validation rules
                'required' => in_array('required', $rules),
            ];

            if ($type_data['type'] == 'select') {
                $resp['options'] = array_combine((array)$type_data['options'], (array)$type_data['options']);
            }
            if ($type_data['type'] == 'number') {
                $resp['max'] = $type_data['max'] ?? '';
                $resp['min'] = $type_data['min'] ?? 0;
            }
            return $resp;

        });

        $variable_forms = $variable_forms->filter()->values();

        return
        array_merge(
            [[
                "key" => "location",
                'col' => 'w-1/2 p-2',
                "name" => "Server Location ",
                "description" =>  count($locations_options) ? "Where do you want us to deploy your server?" : "There are no available server locations. Contact an administrator",
                "type" => "select",
                "disabled" => !count($locations_options),
                "options" => $locations_options,
                "rules" => ['required'],
                'required' => true
            ]],
            $variable_forms->all()
            );
    }

    private static function determineType(array $rules): array {
        $response = ['type' => 'text'];
        $rulesString = implode('|', $rules);

        // Check for boolean or bool
        if (in_array('boolean', $rules) || in_array('bool', $rules)) {
            $response['type'] = 'bool';
        } elseif (preg_match('/\|in:(.*)/', $rulesString, $matches)) {
            // Select type for 'in:' rule
            $response['type'] = 'select';
            $response['options'] = explode(',', $matches[1]);
        } elseif (in_array('numeric', $rules)) {
            // Type for 'numeric' rule
            $response['type'] = 'number';
            // Finding the maximum value
            if (preg_match('/max:(\d+)/', $rulesString, $maxMatches)) {
                $response['max'] = $maxMatches[1];
            }
            // Finding the minimum value
            if (preg_match('/min:(\d+)/', $rulesString, $minMatches)) {
                $response['min'] = $minMatches[1];
            }
        }
        return $response;
    }


    /**
     * Define buttons shown at order management page
     *
     * @return array
     */
    public static function setServiceButtons(Order $order): array
    {
        $login_to_panel = settings('encrypted::pterodactyl::sso_secret') ? [
            "name" => __('client.login_to_panel'),
            "icon" => '<i class="bx bx-terminal"></i>',
            "color" => "primary",
            "href" => route('pterodactyl.login', $order->id),
            "target" => "_blank",
        ] : [];

        $ip = trim(Pterodactyl::serverIP($order->id));
        $server_ip = [
            "tag" => 'button',
            "name" => $ip,
            "color" => "emerald",
            "onclick" => "copyToClipboard(this)",
        ];

        return [$login_to_panel, $server_ip];
    }

    /**
     * Define custom permissions for this service
     *
     * @return array
     */
    public static function permissions(): array
    {
        return [
            'pterodactyl.login' => [
                'description' => 'Can this user automatically login to Pterodactyl',
            ],
        ];
    }

    /**
     * This function is responsible for creating an instance of the
     * service. This can be anything such as a server, vps or any other instance.
     *
     * @param array $data
     * @return void
     * @throws BindingResolutionException
     */
    public function create(array $data = []): void
    {
        $server = new Server($this->order);
        $server->create();
        if ($this->location()->stock !== -1) {
            $this->location()->decrement('stock', 1);
        }
    }

    /**
     * Retrieve the key value configured by admins for this package
     */
    private function package($key, $default = NULL)
    {
        if (isset($this->order->package['data'][$key])) {
            return $this->order->package['data'][$key];
        }
        return $default;
    }

    /**
     * Retrieve data of selected egg for this package
     */
    private function egg()
    {
        return json_decode($this->order->package['data']['egg']);
    }

    /**
     * Retrieve selected location by the user if no location
     * is selected, deploy on best suitable location
     */
    private function location()
    {
        return isset($this->order->options['location'])
            ? Location::find($this->order->options['location'])
            : Location::where('stock', '!=', 0)->first();
    }

    /**
     * Retrieve custom options configured by user at checkout
     */
    private function option($key, $default = NULL)
    {
        if (isset($this->order->options[$key])) {
            return $this->order->options[$key];
        }

        return $default;
    }

    /**
     * This function is responsible for upgrading or downgrading an instance of
     * the service. This method is called when a order is upgraded or downgraded
     *
     * @return void
     */
    public function upgrade(Package $oldPackage, Package $newPackage)
    {
        $server = $this->server();
        Pterodactyl::api()->servers->build($server['id'], [
            "allocation" => $server['allocation'],
            'memory' => (integer)$newPackage->data('memory_limit', 0),
            'swap' => (integer)$newPackage->data('swap_limit', 0),
            'disk' => (integer)$newPackage->data('disk_limit', 0),
            'io' => (integer)$newPackage->data('block_io_weight', 500),
            'cpu' => (integer)$newPackage->data('cpu_limit', 100),
            "feature_limits" => [
                "databases" => (integer)$newPackage->data('database_limit', 0),
                "backups" => (integer)$newPackage->data('backup_limit', 0),
                "allocations" => (integer)$newPackage->data('allocation_limit', 0),
            ]
        ]);
    }

    /**
     * This function is responsible for suspending an instance of the
     * service. This method is called when a order is expired or
     * suspended by an admin
     *
     * @return void
     */
    public function suspend(array $data = []): void
    {
        $server = $this->server();
        Pterodactyl::api()->servers->suspend($server['id']);
    }

    /**
     * This function is responsible for unsuspending an instance of the
     * service. This method is called when a order is activated or
     * unsuspended by an admin
     *
     * @return void
     */
    public function unsuspend(array $data = []): void
    {
        $server = $this->server();
        Pterodactyl::api()->servers->unsuspend($server['id']);
    }

    /**
     * This function is responsible for deleting an instance of the
     * service. This can be anything such as a server, vps or any other instance.
     *
     * @return void
     */
    public function terminate(array $data = []): void
    {
        try {
            $server = $this->server();
            Pterodactyl::api()->servers->delete($server['id']);
        } catch (\Exception $e) {
            request()->session()->flash('error', $e->getMessage());
        }

    }

    /**
     * Change the Pterodactyl password
     */
    public function changePassword(Order $order, string $newPassword)
    {
        try {
            // make api request
            $pterodactyl_user = Pterodactyl::user($order->user);

            if (!$order->hasExternalUser()) {
                $order->createExternalUser([
                    'external_id' => $pterodactyl_user['id'],
                    'username' => $pterodactyl_user['email'],
                    'password' => $newPassword,
                    'data' => $pterodactyl_user,
                ]);
            }

            $response = Pterodactyl::api()->users->update($pterodactyl_user['id'], [
                'email' => $pterodactyl_user['email'],
                'username' => $pterodactyl_user['username'],
                'first_name' => $pterodactyl_user['first_name'],
                'last_name' => $pterodactyl_user['last_name'],
                'password' => $newPassword,
            ]);

            $order->updateExternalPassword($newPassword);
        } catch (\Exception $error) {
            return redirect()->back()->withError("Something went wrong, please try again.");
        }

        return redirect()->back()->withSuccess("Password has been changed");
    }

    /**
     * This function retrieves the Pterodactyl server belonging to this order
     * directly from the Pterodactyl API and returns the atributes of that server.
     *
     * @return Server
     */
    private function server()
    {
        return Pterodactyl::server($this->order->id);
    }

}
