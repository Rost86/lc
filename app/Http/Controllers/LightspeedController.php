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
}
	
	
