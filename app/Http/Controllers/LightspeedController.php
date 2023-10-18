<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Carbon\Carbon;

class LightspeedController extends Controller
{
	public function getOrders()
	{
		$apiKey = '951befcc82060d73bf7e00068087231f';
		$apiSecret = '4531e557cd58d69eac5db84e20565dc5';
		$apiUrl = 'https://api.shoplightspeed.com/fc/';
		$client = new Client();
		$limit = '1';

		try {
			$response = $client->request('GET', $apiUrl . '/orders.json?limit=' . $limit, [
				'auth' => [$apiKey, $apiSecret],
			]);

			$orders = json_decode($response->getBody()->getContents(), true)["orders"];
			$formattedData = [];
			
			foreach ($orders as $order) {
				$productArray = [];  // Reset the product array for each order

				// If there's only one product, make it into an array for consistency
				$productUrls = (is_array($order['products']['resource']['url']))
					? $order['products']['resource']['url']
					: [$order['products']['resource']['url']];

				foreach ($productUrls as $productUrl) {
					
					$productResponse = $client->request('GET', $apiUrl .  $productUrl.'.json', [
						'auth' => [$apiKey, $apiSecret],
					]);
					$productDetails = json_decode($productResponse->getBody()->getContents(), true);
					// Assuming the response has these fields. Adjust as necessary.

					foreach ($productDetails["orderProducts"] as $orderProduct) {
						
						$productResponseDetails = $client->request('GET', $orderProduct["product"]["resource"]["link"], [
							'auth' => [$apiKey, $apiSecret],
						]);
						$fetchedProductDetails = json_decode($productResponseDetails->getBody()->getContents(), true);
						$productURL = 'https://www.horscircuits.ca/' . $fetchedProductDetails['product']['url']. '.html' ?? "";  
						$productImgURL = $fetchedProductDetails['product']['image']['src'] ?? "";   
						$productData = [
							"product_id" => $orderProduct["id"],
							"product_name" => $orderProduct["productTitle"],
							"product_brand" => $orderProduct["brandTitle"],
							//"product_category" => "", 
							"product_price" => $orderProduct["priceExcl"],
							//"product_variant" => $orderProduct["variantTitle"],
							"product_quantity" => $orderProduct["quantityOrdered"],
							"product_url" => $productURL,
							"product_img_url" => $productImgURL  
						];

						$productArray[] = $productData;  
					}        
				}
				$tax = $order['priceIncl'] - $order['priceExcl'];
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

			$this->sendToCustomerLabs($formattedData);

			return response()->json($formattedData);
		} catch (\Exception $e) {
			// Handle any exceptions or errors here
			return response()->json(['error' => $e->getMessage()], 500);
		}
	}
	
	private function sendToCustomerLabs($data)
	{
    $client = new Client();

    $customerLabsEndpoint = 'https://hook.customerlabs.co/source/c-series-257/';  // Replace with the actual endpoint
    $customerLabsApiKey = 'M5iPmN0Do994KJy4jBcWj9F1NFRvg8Km9dUzlZM6';  // Replace with your actual API key

		try {
			$response = $client->request('POST', $customerLabsEndpoint, [
				'headers' => [
					'Authorization' => 'Bearer ' . $customerLabsApiKey,
					'Content-Type' => 'application/json',
				],
				'json' => $data,
			]);

			// Check the response if needed
			$responseBody = json_decode($response->getBody()->getContents(), true);

			if ($response->getStatusCode() === 200) {
				return $responseBody;
			} else {
				// Handle the error response from CustomerLabs
				throw new \Exception("Failed to send data to CustomerLabs. Response: " . json_encode($responseBody));
			}
		} catch (\Exception $e) {
			// Handle the exception
			// This can be logging the error, returning a specific error message, etc.
			throw $e;
		}
	}
}
	
	
