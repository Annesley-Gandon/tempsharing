<?php
namespace App\Services;

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Rest;
use Shopify\Auth\Session;
use Shopify\Rest\Admin2023_04\Product;
use Shopify\Rest\Admin2023_04\Metafield;
use Shopify\Rest\Admin2023_04\Variant;
use Shopify\Rest\Admin2023_04\Image;
use Shopify\Utils;
use Carbon\Carbon;
use Exception;
use Shopify\Rest\Admin2023_04\CustomCollection;
use Shopify\Rest\Admin2023_04\Collect;
use Shopify\Clients\Graphql;

use Symfony\Component\VarDumper\VarDumper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * https://shopify.dev/docs/api/admin-graphql/2023-04/mutations/bulkOperationRunMutation
 * https://shopify.dev/docs/api/usage/bulk-operations/imports
 * - Create a JSONL file and include GraphQL variables
 * - Upload the file to Shopify
 *   - The image path is there, how to operate the image?
 * - Create a bulk mutation operation
 * - Wait for the operation to finish
 * - Retrieve the results
 */
class ShopifyApi {
    use \App\Traits\MyUtils;
    private $_session;
    private $_client;
    private $_graphqlClient;

    private $_allCustomCollections = [];

    public function __construct() {
        Context::initialize(
            apiKey: env('MIGRATION_SHOPIFY_API_KEY'),
            apiSecretKey: env('MIGRATION_SHOPIFY_API_SECRET'),
            scopes: $this->commaSeperatedStringToArray(env('MIGRATION_SHOPIFY_APP_SCOPE')),
            hostName: env('MIGRATION_SHOPIFY_APP_HOSTNAME'),
            sessionStorage: new FileSessionStorage('/tmp/php_sessions'),
            apiVersion: env('MIGRATION_SHOPIFY_API_VERSION'),
            isEmbeddedApp: true,
            isPrivateApp: false,
        );
        
        $this->_session = new Session("shopifySession", env('MIGRATION_SHOPIFY_APP_HOSTNAME'), true, "1234");
        $this->_session->setAccessToken(env('MIGRATION_SHOPIFY_APP_ACCESS_TOKEN'));
        $this->_client = new Rest($this->_session->getShop(), $this->_session->getAccessToken());
        $this->_graphqlClient = new Graphql($this->_session->getShop(), $this->_session->getAccessToken());
    }

    // // build jsonl
    // $jsonlPath = $this->_buildJsonl($data);

    // // generate uploaded URL and parameters
    // $uploadedUrlAndParams = $this->_generatedUploadedUrlANdParams();

    // // upload the jsonl to shopify
    // $response = Http::attach('file', file_get_contents($jsonlPath), 'products.jsonl')
    //     ->post($uploadedUrlAndParams['url'], $uploadedUrlAndParams['parameters']);

    // // create bulk mutation operation
    // $response = $this->_createBulkMutationOperation($uploadedUrlAndParams['parameters']['key']);

    // dd($response);

    public function uploadImageTest() {
        $uploadedUrlAndParams = $this->_uploadImageTest_GenerateUploadUrlAndParams();
        dump($uploadedUrlAndParams);

        $response = $this->_uploadImageTest_UploadAsset($uploadedUrlAndParams);
        dump($response);
        //8435496157476
        
        $response = $this->_uploadImageTest_AttachToProduct($uploadedUrlAndParams['url']);
        dd($response);
    }

    private function _uploadImageTest_GenerateUploadUrlAndParams() {
        // /home/maxwkf/Downloads/alasiamoscato.png
        $queryString = <<<QUERY
        mutation {
            stagedUploadsCreate(input: [
                {
                    filename: "alasiamoscato.png",
                    mimeType: "image/png",
                    resource: IMAGE,
                    fileSize: "407504"
                }
            ])
            {
                stagedTargets {
                    url
                    resourceUrl
                    parameters {
                        name
                        value
                    }
                }
                userErrors {
                    field, message
                }
            }
        }
QUERY;

        $response = $this->_graphqlClient->query(data: $queryString);
        $decodedBody = $response->getDecodedBody();
        
        $result = [
            'url' => $decodedBody['data']['stagedUploadsCreate']['stagedTargets'][0]['url'],
            'parameters' => array_reduce($decodedBody['data']['stagedUploadsCreate']['stagedTargets'][0]['parameters'], function($carry, $item) {
                $carry[$item['name']] = $item['value'];
                return $carry;
            }, [])
        ];

        return $result;
    }

    private function _uploadImageTest_UploadAsset($uploadedUrlAndParams) {
        $response = Http::attach('file',
                $this->resizeImageAndToPng("https://nywines-staging.ccagency2.co.uk/sites/default/files/products-drinks/alasiamoscato.png", 1024, 1024), 'alasiamoscato.png'
            )
            ->post($uploadedUrlAndParams['url'],$uploadedUrlAndParams['parameters']);
    }

    private function _uploadImageTest_AttachToProduct($url) {
/*
This is the final response return.  But it shows Image: Media processing failed.  Looks like this is a bug in Shopify without solution yet.
https://community.shopify.com/c/shopify-discussions/media-upload-failed-image-media-processing-failed/m-p/1769696
array:2 [▼ // app/Services/ShopifyApi.php:83
  "data" => array:1 [▶
    "productCreateMedia" => array:3 [▶
      "media" => array:1 [▶
        0 => array:6 [▶
          "alt" => "test image upload"
          "mediaContentType" => "IMAGE"
          "preview" => array:1 [▶
            "image" => null
          ]
          "status" => "UPLOADED"
          "mediaErrors" => []
          "mediaWarnings" => []
        ]
      ]
      "product" => array:1 [▶
        "id" => "gid://shopify/Product/8435520700708"
      ]
      "mediaUserErrors" => []
    ]
  ]
  "extensions" => array:1 [▶
    "cost" => array:3 [▶
      "requestedQueryCost" => 27
      "actualQueryCost" => 24
      "throttleStatus" => array:3 [▶
        "maximumAvailable" => 1000.0
        "currentlyAvailable" => 976
        "restoreRate" => 50.0
      ]
    ]
  ]
]
*/


        $productId = 8435520700708;
        $queryString = <<<QUERY
        mutation createProductMedia {
            productCreateMedia(productId: "gid://shopify/Product/{$productId}", media: [
              {
                originalSource: "{$url}",
                alt: "test image upload",
                mediaContentType: IMAGE
              }
            ]) {
              media {
                ... fieldsForMediaTypes
                mediaErrors {
                  code
                  details
                  message
                }
                mediaWarnings {
                  code
                  message
                }
              }
              product {
                id
              }
              mediaUserErrors {
                code
                field
                message
              }
            }
          }
          
          fragment fieldsForMediaTypes on Media {
            alt
            mediaContentType
            preview {
              image {
                id
              }
            }
            status
            ... on Video {
              id
              sources {
                format
                height
                mimeType
                url
                width
              }
            }
            ... on ExternalVideo {
              id
              host
              originUrl
            }
            ... on Model3d {
              sources {
                format
                mimeType
                url
              }
              boundingBox {
                size {
                  x
                  y
                  z
                }
              }
            }
          }
QUERY;

        $response = $this->_graphqlClient->query(data: $queryString);
        return $response->getDecodedBody();
    }


    private function _uploadImage($data) {
        // https://shopify.dev/docs/apps/online-store/media/products
        // https://shopify.dev/docs/api/admin-graphql/2023-04/mutations/productUpdateMedia


        // create bulk image operation
        $uploadedUrlAndParams = (function() use ($data) {
            $queryString = <<<QUERY
                mutation {
                    stagedUploadsCreate(input: [
                    {
                        filename: "test.png",
                        mimeType: "image/png",
                        resource: IMAGE,
                        fileSize: "407504"
                    }
                    ])
                    {
                    stagedTargets {
                        url
                        resourceUrl
                        parameters {
                        name
                        value
                        }
                    }
                    userErrors {
                        field, message
                    }
                    }
            }
QUERY;

            $response = $this->_graphqlClient->query(data: $queryString);
            $decodedBody = $response->getDecodedBody();
            
            $result = [
                'url' => $decodedBody['data']['stagedUploadsCreate']['stagedTargets'][0]['url'],
                'parameters' => array_reduce($decodedBody['data']['stagedUploadsCreate']['stagedTargets'][0]['parameters'], function($carry, $item) {
                    $carry[$item['name']] = $item['value'];
                    return $carry;
                }, [])
            ];
    
            return $result;
        })();
        
        
        $response = Http::attach('file', $this->resizeImageAndToPng(env('MIGRATION_ORIGINAL_SITE_URL') . $data['Product image (Variant)'], 1024, 1024), 'alasiamoscato.png')->post($uploadedUrlAndParams['url'],$uploadedUrlAndParams['parameters']);

        dd($response);

        // $response = Http::attach('file', $this->resizeImageAndToPng(env('MIGRATION_ORIGINAL_SITE_URL') . $data['Product image (Variant)'], 1024, 1024), 'alasiamoscato.png')
        //     ->post($uploadedUrlAndParams['url'], $uploadedUrlAndParams['parameters']);
    }



    /**
     * https://shopify.dev/docs/api/admin-graphql
     */
    public function uploadProductsByGraphQl($data) {
        // dd($this->getLastProduct());

        // build jsonl
        $jsonlPath = $this->_buildJsonl($data);

        // generate uploaded URL and parameters
        $uploadedUrlAndParams = $this->_generatedUploadedUrlANdParams();

        // upload the jsonl to shopify
        $response = Http::attach('file', file_get_contents($jsonlPath), 'products.jsonl')
            ->post($uploadedUrlAndParams['url'], $uploadedUrlAndParams['parameters']);

        // create bulk mutation operation
        $response = $this->_createBulkMutationOperation($uploadedUrlAndParams['parameters']['key']);

        if (isset($response['data']['bulkOperationRunMutation']['bulkOperation']['status']) && $response['data']['bulkOperationRunMutation']['bulkOperation']['status'] == 'CREATED') {
            $product = $this->getLastProduct();
            dump($product);
        }

        dd($response);

        // retrieve the results?
    }


    /**
     * {
     *   "input":{
     *       "title":"Alasia Moscato d'Asti - graphql",
     *       "descriptionHtml":"A really solild Schnell with a large amount of the grapes coming from our own Small Town vineyard purchased in October 2016.  60% Shiraz mainly from our vineyard and 40% Neldner Grenache, a long term Magpie grower. A combination of old oak, tank, new oak all play their part in making this bright, almost perfumed red. Red and black berry, white pepper, spice and a touch of oak. Full flavoured, with lovely bright fresh berry and cherry fruit, spice, pepper and oak.  Bright and almost creamy. A very attractive juicy style with some depth. Versatile food style too.",
     *       "vendor":"Araldica",
     *       "tags":"test,test_20230703",
     *       "productType":"Sparkling"
     *   },
     *   "media": [{
     *       "alt":"alasiamoscato",
     *       "mediaContentType":"IMAGE",
     *       "originalSource":"https://nywines-staging.ccagency2.co.uk/sites/default/files/products-drinks/alasiamoscato.png"
     *   }]
     * }
     * 
     * https://shopify.dev/docs/api/usage/bulk-operations/imports
     * https://shopify.dev/docs/apps/online-store/media/products
     * https://shopify.dev/docs/apps/online-store/media/products#generate-the-upload-url-and-parameters
     */
    private function _buildJsonl($data) {
        $preparedData = array_reduce($data, function($carry, $row) {
            
            $jsonData = ['input' => [
                'title' => (function() use ($row) {
                    $title = $row['Title'];
                    // if ($row['Case Size'] > 1) {
                    //     $title .= " Case({$row['Case Size']})";
                    // }
                    return $title . ' - graphql';
                })(),
                'descriptionHtml' => $row['Description'],
                // https://community.shopify.com/c/graphql-basics-and/product-image-upload-using-graphql-without-url/td-p/531790
                // https://shopify.dev/docs/api/admin-graphql/2023-04/mutations/stagedUploadsCreate
                // https://shopify.dev/docs/api/admin-graphql/2023-04/mutations/productCreateMedia
                // https://shopify.dev/docs/api/admin-graphql/2023-04/objects/productvariant
                'variants' => [
                    [
                        'price' => $row['Price'],
                        'sku' => $row['SKU (Variant)'],
                        'inventoryQuantities' => [
                            "availableQuantity" => intval($row['Stock Total']),
                            "locationId" => "gid://shopify/Location/79914041636"
                        ],
                        'weight' => floatval(str_replace(' kg', '', $row['Weight'])),
                        'weightUnit' => 'KILOGRAMS',
                        'inventoryItem' => [
                            'tracked' => true
                        ]
                    ]
                ],
                'productCategory' => [
                    // Wine: 1707
                    'productTaxonomyNodeId' => "gid://shopify/ProductTaxonomyNode/" . $this->getProductCategoryIdFromProductType($row['Type'])
                ],
                'metafields' => $this->_handleProductMetafields($row),
                'vendor' => $row['Producer'],
                // 'status' => $row['Published'] == 'On' ? 'active' : 'draft',
                'tags' => "test,test_".Carbon::now()->format('Ymd'),
                'productType' => $row['Type'],
                ]];
            
            if ($row['Product image (Variant)']) {
                // https://shopify.dev/docs/api/admin-graphql/2023-04/input-objects/CreateMediaInput
                // if the size or resolution of picture does not fit Shopify requirement
                //   , the image will not be uploaded
                //   , but the product will be created.
                $jsonData['media'] = [
                    [
                        "alt" => "",
                        "mediaContentType" => "IMAGE",
                        "originalSource" => env('MIGRATION_ORIGINAL_SITE_URL') . $row['Product image (Variant)']
                    ]
                    ];
            }
            
            $carry[][] = $jsonData;
            // $carry[$row['ID']][] = $jsonData;

            return $carry;
        }, []);
        // dd($preparedData);
        $filename = 'products_' . Carbon::now()->format('YmdHis') . '.jsonl';
        array_walk($preparedData, function(&$item) use ($filename) {
            Storage::disk('local')->append($filename, json_encode($item));
        });
        return Storage::path($filename);
    }

    public function getProductCategoryIdFromProductType($productType) {
        // https://help.shopify.com/txt/product_taxonomy/en.txt?shpxid=2125749a-A7DD-4AF2-D6E8-F329B6DCABD7
        $productCategoryId = [
            'Wine' => 1707,
            'Gin' => 1698,
            'Rum' => 1700,
            'Liqueurs' => 1699,
            'Whiskey' => 1706,
            'Beer' => 1688,
            'Cooking & Baking Ingredients' => 1776,
            'Vodka' => 1705,
            'Alcoholic Beverages' => 1687
        ];


        return [
            'Armagnac' => $productCategoryId['Alcoholic Beverages'],
            'Beer' => $productCategoryId['Beer'],
            'Calvados' => $productCategoryId['Alcoholic Beverages'],
            'Cognac' => $productCategoryId['Alcoholic Beverages'],
            'Fortified' => $productCategoryId['Alcoholic Beverages'],
            'Gin' => $productCategoryId['Gin'],
            'Grappa' => $productCategoryId['Alcoholic Beverages'],
            'Liqueur' => $productCategoryId['Liqueurs'],
            'Olive Oil' => $productCategoryId['Cooking & Baking Ingredients'],
            'Orange' => $productCategoryId['Alcoholic Beverages'],
            'Red' => $productCategoryId['Wine'],
            'Rose' => $productCategoryId['Alcoholic Beverages'],
            'Rum' => $productCategoryId['Rum'],
            'Sherry' => $productCategoryId['Alcoholic Beverages'],
            'Sparkling' => $productCategoryId['Alcoholic Beverages'],
            'Sweet' => $productCategoryId['Alcoholic Beverages'],
            'Vodka' => $productCategoryId['Vodka'],
            'Whisky' => $productCategoryId['Whiskey'],
            'White' => $productCategoryId['Wine'],
        ][$productType] ?? $productCategoryId['Alcoholic Beverages'];
    }

    private function _createBulkMutationOperation($key) {
        // https://shopify.dev/docs/api/usage/bulk-operations/imports
        // https://shopify.dev/docs/api/admin-graphql/2023-04/mutations/productCreate


        $productCreateQuery = <<<QUERY
        mutation productCreate(\$input: ProductInput!, \$media: [CreateMediaInput!]) {
            productCreate(input: \$input, media: \$media) {
              product {
                id
                title
                descriptionHtml
                variants(first: 10) {
                  edges {
                    node {
                      id
                      title
                      inventoryQuantity
                    }
                  }
                }
              }
              userErrors {
                message
                field
              }
            }
          }
QUERY;        


        $queryString = <<<QUERY
        mutation {
            bulkOperationRunMutation(
              mutation: "{$productCreateQuery}",
              stagedUploadPath: "{$key}") {
              bulkOperation {
                id
                url
                status
              }
              userErrors {
                message
                field
              }
            }
          }
QUERY;
    $response = $this->_graphqlClient->query(data: $queryString);

    return $response->getDecodedBody();
    }



    private function _generatedUploadedUrlANdParams() {
        $queryString = <<<QUERY
        mutation {
            stagedUploadsCreate(input:{
              resource: BULK_MUTATION_VARIABLES,
              filename: "bulk_op_vars",
              mimeType: "text/jsonl",
              httpMethod: POST
            }){
              userErrors{
                field,
                message
              },
              stagedTargets{
                url,
                resourceUrl,
                parameters {
                  name,
                  value
                }
              }
            }
          }
QUERY;
        $response = $this->_graphqlClient->query(data: $queryString);
        $decodedBody = $response->getDecodedBody();

        $result = [
            'url' => $decodedBody['data']['stagedUploadsCreate']['stagedTargets'][0]['url'],
            'parameters' => array_reduce($decodedBody['data']['stagedUploadsCreate']['stagedTargets'][0]['parameters'], function($carry, $item) {
                $carry[$item['name']] = $item['value'];
                return $carry;
            }, [])
        ];

        return $result;
    }

    
    public function testGraphQl() {
        //https://shopify.dev/docs/api/usage/bulk-operations/imports
        dump($this->_testRetrieveTenProducts());
        dump($this->_testCreateProduct());
    }

    private function _testRetrieveTenProducts() {
        $queryString = <<<QUERY
    {
        products (first: 10) {
            edges {
                node {
                    id
                    title
                    descriptionHtml
                }
            }
        }
    }
QUERY;
    $response = $this->_graphqlClient->query(data: $queryString);
    return $response->getDecodedBody();

    }
    private function _testCreateProduct() {
        $queryUsingVariables = <<<QUERY
        mutation productCreate(\$input: ProductInput!) {
            productCreate(input: \$input) {
                product {
                    id
                }
            }
        }
QUERY;
        $variables = [
            "input" => [
                "title" => "TARDIS",
                "descriptionHtml" => "Time and Relative Dimensions in Space",
                "productType" => "Time Lord technology"
            ]
        ];
        $response = $this->_graphqlClient->query(data: ['query' => $queryUsingVariables, 'variables' => $variables]);
        return $response->getDecodedBody();
    }


    public function getLastProduct() {

        $query = <<<QUERY
        {
            products(first: 1, reverse: true) {
            
            edges {
                node {
                  id
                  title
                  handle
                  status
                  tags
                  productCategory {
                    productTaxonomyNode {
                        fullName
                        id
                        name
                    }
                  }
                  variants(first: 20) {
                    edges {
                      node {
                        id
                        title
                        sku
                        weight
                        inventoryQuantity
                        inventoryPolicy
                        inventoryManagement
                        metafields(first:20) {
                          edges {
                            node {
                              id
                              key
                              value
                              namespace
                            }
                          }
                        }
                      }
                    }
                  }
                  metafields(first:20) {
                    edges {
                        node {
                            id
                            key
                            value
                            namespace
                        }
                    }
                  }
                  resourcePublicationOnCurrentPublication {
                    publication {
                      name
                      id
                    }
                    publishDate
                    isPublished
                  }
                  
                }
              }
            }
        }
QUERY;

        $response = $this->_graphqlClient->query(data: ['query' => $query]);
        return $response->getDecodedBody();
    }

    public function getProductByIdGraphQl($productId) {

        if (is_int($productId)) {
            $productId = "gid://shopify/Product/{$productId}";
        }

        $query = <<<QUERY
        product(id: "{$productId}") {
            title
            description
          }
QUERY;

        $response = $this->_graphqlClient->query(data: ['query' => $query]);
        return $response->getDecodedBody();
    }
        
    public function saveProduct($data) {
        //❤️‍🔥 The API cannot handle Product Category
        //❤️‍🔥 There is an API rate limit 40/min
        //❤️‍🔥 I have created a view in nywines.ddev.site for getting product detail

        // dd(Collect::all ($this->_session));

        $product = new Product($this->_session);
        $product->title = $data['Title'];
        $product->body_html = $data['Description'];
        $product->images = $this->_handleImage($data);
        
        /**
         * Note: The Shopify save some product detail as product variant.
         *      It does not necessarily mean that the product has multiple variants.
         */
        $product->variants = $this->_handleProductVariant($data);
        $product->vendor = $data['Producer'];
        $product->status = $data['Published'] == 'On' ? 'active' : 'draft';
        $product->tags = "test,test_".Carbon::now()->format('Ymd');
        $product->product_type = $data['Type'];
        // $product->collections = [$productVariationType];
        $product->metafields = $this->_handleProductMetafields($data);
        $product->save(
            true, // Update Object
        );
        $this->_saveCollections($product, $data);

        return $product;
    }

    private function _handleImage($data) {
        $image = new Image($this->_session);
        $image->attachment = $this->resizeImageAndToPng(env('MIGRATION_ORIGINAL_SITE_URL') . $data['Product image (Variant)'], 1024, 1024);
        return [$image];
    }

    private function _handleProductVariant($data) {
        $variant = new Variant($this->_session);
        $variant->price = $data['Price'];
        $variant->sku = $data['SKU (Variant)'];
        $variant->inventory_quantity = $variant->old_inventory_quantity = $data['Stock Total'];
        $variant->weight = str_replace(' kg', '', $data['Weight']);
        $variant->grams = $variant->weight * 1000;
        $variant->weight_unit = 'kg';
        return [$variant];
    }

    private function _handleProductMetafields($data) {
        $result = [];
            foreach([
                'supplier' => $data['Supplier'],
                //inbond
                'bottle_size' => $data['Bottle size'],
                'abv' => $data['ABV'],
                'country' => $data['Country'],
                'region' => $data['Region'],
                'grape_variety' => $this->formatMultiValueToSingleTextField($data['Grape Variety']),
                'farming_style' => $this->formatMultiValueToSingleTextField($data['Farming Style']),
                'vintage' => $data['Vintage'],
                'dietary' => $this->formatMultiValueToSingleTextField($data['Dietary']),
                'producer' => $data['Producer'],
                'readiness' => $data['Readiness'],
                'wine_club_grade' => $data['Wine Club Grade'],
                'drupal_product_id' => $data['ID'],
            ] as $key => $value) {
                if ($value) {
                    $result[] = [
                        "key" => $key,
                        "value" => $value,
                        "namespace" => "custom"
                    ];
                }
            }
        return $result;
    }

    private function _saveCollections(&$product, $data) {
        $data['Product type'] == 'In Bond' && $this->_putToCollection($product, 'In Bond');
        $data['Product type'] == 'Duty Paid' && $this->_putToCollection($product, 'Duty Paid');
        !empty($data['Promotional Pages']) && $this->_putToCollectionPromotionalPages($product, $data['Promotional Pages']);
    }







    public function _putToCollectionPromotionalPages($product, $promotionalPages) {

        $result = [];
        if (!is_array($promotionalPages)) {
            $promotionalPages = $this->commaSeperatedStringToArray($promotionalPages);
        }

        array_walk($promotionalPages, function($promotionalPage) use ($product, &$result) {
            $result[] = $this->_putToCollection($product, $promotionalPage);
        });

        return $result;

    }



    private function _putToCollection(mixed $product, string $collectionName) {
        if (!$collectionName) return false;

        // find custom collection by title
        $customCollection = $this->getCustomCollectionByTitle($collectionName);

        // if custom collection is empty, create a new custom collection with collectionName
        if (empty($customCollection)) {
            $customCollection = $this->createCustomCollection($collectionName);
        } else {
            $customCollection = array_values($customCollection)[0];
        }

        // add product to custom collection
        $collect = new Collect($this->_session);
        $collect->product_id = is_int($product) ? $product : $product->id;
        $collect->collection_id = $customCollection->id;
        $collect->save(
            true, // Update Object
        );

    }

    public function createCustomCollection($title) {
        if (!$title) return false;
        $customCollection = new CustomCollection($this->_session);
        $customCollection->title = $title;
        $customCollection->save(
            true, // Update Object
        );
        $this->updateAllCustomCollections();
        return $customCollection;
    }

    public function getCustomCollectionByTitle($title) {
        if (!$this->_allCustomCollections) $this->updateAllCustomCollections();

        return array_filter($this->_allCustomCollections, function($customCollection) use ($title) {
            return $customCollection->title == $title;
        });
    }

    public function updateAllCustomCollections() {
        $this->_allCustomCollections = CustomCollection::all(
            $this->_session, // Session
            [], // Url Ids
            [], // Params
        );
    }

    public function saveProductImage($productId, $imageUrl) {
        $image = new Image($this->_session);
        $image->product_id = $productId;
        $image->src = $imageUrl;
        $image->save(
            true, // Update Object
        );

        return $image;
    }

    public function getProduct($id) {
        
        return Product::find(
            $this->_session, // Session
            $id, // Id
            [], // Url Ids
            [], // Params
        );
    }

    public function getProductVariant($id) {
        return Variant::all(
            $this->_session, // Session
            ["product_id" => $id], // Url Ids
            [], // Params
        );
    }

    public function getProducts() {
        // https://shopify.dev/docs/api/admin-rest/2023-04/resources/product#get-products?ids=632910392,921728736
        return Product::all(
            $this->_session, // Session,
        );
    }

    public function getTenProducts() {
        return Product::all(
            $this->_session, // Session,
            [],
            ['limit' => 10],
        );
    }

    public function getProductMetaFields($productId) {
        dd(Metafield::all(
            $this->_session, // Session
            [], // Url Ids
            ["metafield" => ["owner_id" => $productId, "owner_resource" => "product"]], // Params
        ));
    }

}
