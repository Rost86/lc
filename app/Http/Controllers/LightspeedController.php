<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Carbon\Carbon;
use App\Models\Configuration; 

class LightspeedController extends Controller
{
	public function getOrders()
	{
		// Fetch active configurations from the database
		$configurations = Configuration::where('is_active', 1)->get();

		$allFormattedData = [];
		// Get the start and end of the current day
		$startOfDay = Carbon::now()->startOfDay()->toDateTimeString();
		$endOfDay = Carbon::now()->endOfDay()->toDateTimeString();

		foreach ($configurations as $config) {
			$apiKey = $config->lightspeed_api_key;
			$apiSecret = $config->lightspeed_api_secret;
			$apiUrl = $config->lightspeed_api_url;

			// Initialize the Guzzle client
			$client = new Client();


			try {
            // Fetch orders from Lightspeed from the current day only
            $response = $client->request('GET', $apiUrl . '/orders.json?createdAtMin=' . $startOfDay . '&createdAtMax=' . $endOfDay, [
                'auth' => [$apiKey, $apiSecret],
            ]);


				$orders = json_decode($response->getBody()->getContents(), true)["orders"];
				$formattedData = [];
				
				// Loop through each order to format data and fetch associated products
				foreach ($orders as $order) {
					$productArray = [];
					
					// Check if order has multiple products
					$productUrls = (is_array($order['products']['resource']['url']))
						? $order['products']['resource']['url']
						: [$order['products']['resource']['url']];
						
					// Loop through each product URL to fetch product details
					foreach ($productUrls as $productUrl) {
						// Fetch product details from Lightspeed
						$productResponse = $client->request('GET', $apiUrl .  $productUrl.'.json', [
							'auth' => [$apiKey, $apiSecret],
						]);

						$productDetails = json_decode($productResponse->getBody()->getContents(), true);
						
						// Loop through products and fetch additional details
						foreach ($productDetails["orderProducts"] as $orderProduct) {
							$productResponseDetails = $client->request('GET', $orderProduct["product"]["resource"]["link"], [
								'auth' => [$apiKey, $apiSecret],
							]);
							$fetchedProductDetails = json_decode($productResponseDetails->getBody()->getContents(), true);
							// Define product URLs
							$productURL = 'https://www.horscircuits.ca/' . $fetchedProductDetails['product']['url']. '.html' ?? "";
							$productImgURL = $fetchedProductDetails['product']['image']['src'] ?? ""; 
							// Construct the product data array
							$productData = [
								"product_id" => $orderProduct["id"],
								"product_name" => $orderProduct["productTitle"],
								"product_brand" => $orderProduct["brandTitle"],
								"product_price" => $orderProduct["priceExcl"],
								"product_quantity" => $orderProduct["quantityOrdered"],
								"product_url" => $productURL,
								"product_img_url" => $productImgURL  
							];

							$productArray[] = $productData;
						}
					}
					// Calculate tax based on order details
					$tax = $order['priceIncl'] - $order['priceExcl'];
					// Format the final order data
					$formattedData[] = [
						'event_name' => 'Purchased',
						'user_id' => $order['customer']['resource']['id'],
						//'group_id' => $order['remoteIp'],
						'ip' => $order['remoteIp'],
						'language' => $order['language']['title'],
						'source' => $order['remoteIp'],
						'user_agent' => $order['userAgent'],
						'phone' => $order['phone'],
						'email' => $order['email'],
						"paymentStatus" => $order['paymentStatus'],
						'timestamp' => $order['createdAt'],
						'products' => $productArray,
						'properties' => [
							"order_id" => $order['id'],
							"transaction_id" => $order['paymentId'],
							"tax" => round($tax, 2),
							"revenue" => round($order['priceExcl'], 2),
							"currency" => "CAD",
							"coupon" => $order['discountCouponCode'],
							"shipping" => round($order['shipmentBasePriceIncl'], 2),
							"step" => 2,
							"page_title" => "Cart",
							"page_url" => "/cart/"
						]
					];
				}
				// Send the formatted data to CustomerLabs
				$this->sendToCustomerLabs($formattedData, $config->customerlabs_api_key, $config->customerlabs_endpoint);
				// Merge the current formatted data with the master array
				//$allFormattedData = array_merge($allFormattedData, $formattedData);
				//return response()->json($allFormattedData);
				
			} catch (\Exception $e) {
				 // Return the error message in case of an exception
				return response()->json(['error' => $e->getMessage()], 500);
			}
		}

	}
	
	private function sendToCustomerLabs($data, $customerLabsApiKey, $customerLabsEndpoint)
	{
		// Initialize the Guzzle client
		$client = new Client();
		try {
			// Send the data to CustomerLabs using a POST request
			$response = $client->request('POST', $customerLabsEndpoint, [
				'headers' => [
					'Authorization' => 'Bearer ' . $customerLabsApiKey,
					'Content-Type' => 'application/json',
				],
				'json' => $data,
			]);

			$responseBody = json_decode($response->getBody()->getContents(), true);
			// Check if the response status is not 200; throw an exception if it's an error
			if ($response->getStatusCode() !== 200) {
				
				throw new \Exception("Failed to send data to CustomerLabs. Response: " . json_encode($responseBody));
			}

			return $responseBody;
		} catch (\Exception $e) {
			// Throw the caught exception
			throw $e;
		}
	}
	public function getRetailOrders()
    {
        $accessToken = $this->getAccessToken();
        $client = new Client();
        $timezoneOffset = '-0400'; // Replace with your shop's timezone offset
        $todayStart = Carbon::today()->subdays(1)->format('Y-m-d\T00:00:00') . $timezoneOffset;
        try {
                $response = $client->get('https://api.lightspeedapp.com/API/Account/255201/Sale.json?timeStamp=>,' . $todayStart . '&load_relations=all&completed=true', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                ]);

                $data = json_decode((string) $response->getBody(), true);
				if (!isset($data['Sale'])) {
					// Handle the case where 'Sale' key does not exist
					throw new \Exception("The 'Sale' key does not exist in the response data.");
				}
				$saleArray = $data['Sale'];
				$filteredSales = array_filter($saleArray, function ($sale) {
					return isset($sale['displayableSubtotal']) && floatval($sale['displayableSubtotal']) >= 0;
				});
                $formattedData = [];

				foreach ($filteredSales as $sale) {
					$productArray = [];
					// Check if SaleLines is set and is an array
					if (isset($sale['SaleLines']['SaleLine'])) {
						// Check if SaleLine is an array of arrays (multiple sale lines)
						if (isset($sale['SaleLines']['SaleLine'][0]) && is_array($sale['SaleLines']['SaleLine'][0])) {
							foreach ($sale['SaleLines']['SaleLine'] as  $line) {
								$productArray[] = $this->formatProductData($line);
							}
						} else {
							// SaleLine is a single array (one sale line)
							$productArray[] = $this->formatProductData($sale['SaleLines']['SaleLine']);
						}
					}
					$formattedData[] = [
						'event_name' => 'Purchased',
						'user_id' => $sale['customerID'] ?? '',
						'ip' => '', 
						'language' => 'Français (CA)', 
						'source' => '', 
						'user_agent' => '', 
						'phone' => $sale['Customer']['Contact']['Phones']['ContactPhone']['number'] ?? '',
						'email' => $sale['Customer']['Contact']['Emails']['ContactEmail']['address'] ?? '',
						"paymentStatus" => 'completed', 
						'timestamp' => $sale['timeStamp'] ?? '',
						'products' => $productArray,
						'properties' => [
							"order_id" => $sale['saleID'] ?? '',
							"transaction_id" => $sale['SalePayments']['SalePayment']['salePaymentID'] ?? '', // 
							"tax" => $sale['taxTotal'] ?? '',
							"revenue" => $sale['total'] - $sale['taxTotal']  ?? '',
							"currency" => "CAD", 
							"coupon" => '', 
							"shipping" => '', 
							"step" => 2,
							"page_title" => "Cart",
							"page_url" => "/cart/"
						]
					];


			
				}
				$this->sendRdata($formattedData);	

		return $formattedData;

        } catch (\Exception $e) {
            // Handle exception
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
	
	public function sendRdata($cdate)
    {
		$rsEndpoint = 'https://hook.customerlabs.co/source/r-series-4012/';
		$apiKey = 'M5iPmN0Do994KJy4jBcWj9F1NFRvg8Km9dUzlZM6';
		foreach ($cdate as $date) {		
			$this->sendToCustomerLabs($date, $apiKey, $rsEndpoint);
		}	
	}
	
    public function getAccessToken()
    { 
        $client = new Client();

        try {
            $response = $client->post('https://cloud.lightspeedapp.com/oauth/access_token.php', [
                'form_params' => [
					'refresh_token' => '8fe00c169e4f464fb6906dd29e45fa34b91691f0',
                    'client_id' => '19de0d361648255df7b3a5fd752d97fc19b2de1fc9bf617bc23ecaac4981b784',
                    'client_secret' => '3b0077f929a1d6d71e39a30d518a9297c08608e752cb61ad6591576fe6ceb3a2',
					'code' => 'adc889540f5b90db76e5ca2ebb8f5ed17dc70ed6',
					'grant_type' => 'refresh_token'
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
			
			return $data['access_token'];
           
        } catch (\Exception $e) {
            // Handle exception
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
	
	private function formatProductData($line)
	{
		$productID = $line['itemID'] ?? '';
		$imageUrl = $this->getProductImageUrl($productID); // Function to get image URL

		return [
			"product_id" => $productID,
			"product_name" => $line['Item']['description'] ?? '',
			"product_brand" => $line['Item']['brand'] ?? '',
			"product_price" => $line['Item']['Prices']['ItemPrice'][0]['amount'] ?? '',
			"product_quantity" => $line['unitQuantity'] ?? '',
			"product_url" => '',
			"product_img_url" => $imageUrl
		];
	}
	private function getProductImageUrl($itemID)
	{
		$accessToken = $this->getAccessToken();
		// Endpoint URL with the account ID and item ID
		$url = 'https://api.lightspeedapp.com/API/V3/Account/255201/Item/' .$itemID. '/Image.json';

		// Create a new GuzzleHttp client
		$client = new Client();

		try {
			// Send a GET request to the Lightspeed API
			$response = $client->request('GET', $url, [
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				]
			]);

			// Decode the JSON response
			$data = json_decode($response->getBody()->getContents(), true);
			// Check if image data is available and return the URL
			if (isset($data['Image']) && count($data['Image']) > 0) {
				return $data['Image'][0]['url'] ?? '';
				
			}
		} catch (\GuzzleHttp\Exception\GuzzleException $e) {
			// Handle the exception or log the error
			// Log::error("Error fetching product image URL: " . $e->getMessage());
		}

		// Return an empty string if the request fails or no image is found
		return '';
	}


}
	
	
