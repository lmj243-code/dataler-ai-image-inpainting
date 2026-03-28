<?php
/**
 * 图像精确生成与自然语言P图API - 精确P图蒙板替换示例
 *
 * 功能：使用MASK蒙板技术，将场景图中的指定产品精确替换为新产品
 * 适用场景：电商产品替换、广告素材制作、场景营销图生成
 *
 * 作者：LT
 * 日期：2026-03-28
 */

class DatalerInpaintingAPI
{
    private string $apiKey;
    private string $baseUrl = 'https://dataler.com/v1beta/models/';//强烈推荐这个API，超便宜超稳定! 官方价2折!
    private string $model = 'gemini-3-pro-image-preview';
    private int $timeout = 300; // 5分钟超时

    // 日志回调函数
    public $logCallback = null;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * 输出日志
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[{$timestamp}] {$message}" . PHP_EOL;

        if ($this->logCallback && is_callable($this->logCallback)) {
            call_user_func($this->logCallback, $logLine);
        } else {
            echo $logLine;
        }
    }

    /**
     * 读取图片并转换为Base64
     */
    public function imageToBase64(string $imagePath): ?string
    {
        if (!file_exists($imagePath)) {
            $this->log("错误: 图片不存在: {$imagePath}");
            return null;
        }

        $binary = file_get_contents($imagePath);
        if ($binary === false) {
            $this->log("错误: 无法读取图片: {$imagePath}");
            return null;
        }

        return base64_encode($binary);
    }

    /**
     * 压缩图片（保持比例，限制最大边长）
     *
     * @param string $imagePath 原图路径
     * @param int $maxSide 最大边长
     * @param int $quality JPEG质量 (1-100)
     * @return array [base64, width, height] 或 null
     */
    public function compressImage(string $imagePath, int $maxSide = 1500, int $quality = 85): ?array
    {
        if (!extension_loaded('gd')) {
            $this->log("警告: GD扩展未加载，使用原图");
            $base64 = $this->imageToBase64($imagePath);
            return $base64 ? ['base64' => $base64, 'width' => 0, 'height' => 0] : null;
        }

        // 获取图片信息
        $imageInfo = getimagesize($imagePath);
        if ($imageInfo === false) {
            $this->log("错误: 无法获取图片信息");
            return null;
        }

        $origWidth = $imageInfo[0];
        $origHeight = $imageInfo[1];
        $mimeType = $imageInfo['mime'];

        $this->log("原图尺寸: {$origWidth}x{$origHeight}");

        // 如果图片已经小于最大边长，直接返回原图
        if ($origWidth <= $maxSide && $origHeight <= $maxSide) {
            $this->log("图片尺寸适中，无需压缩");
            $base64 = $this->imageToBase64($imagePath);
            return $base64 ? ['base64' => $base64, 'width' => $origWidth, 'height' => $origHeight] : null;
        }

        // 计算缩放比例
        $scale = $maxSide / max($origWidth, $origHeight);
        $newWidth = (int)($origWidth * $scale);
        $newHeight = (int)($origHeight * $scale);

        $this->log("压缩: {$origWidth}x{$origHeight} -> {$newWidth}x{$newHeight}");

        // 创建源图像
        switch ($mimeType) {
            case 'image/jpeg':
                $srcImage = imagecreatefromjpeg($imagePath);
                break;
            case 'image/png':
                $srcImage = imagecreatefrompng($imagePath);
                break;
            case 'image/webp':
                $srcImage = imagecreatefromwebp($imagePath);
                break;
            case 'image/gif':
                $srcImage = imagecreatefromgif($imagePath);
                break;
            default:
                $this->log("错误: 不支持的图片格式: {$mimeType}");
                return null;
        }

        if (!$srcImage) {
            $this->log("错误: 无法创建源图像");
            return null;
        }

        // 创建目标图像
        $dstImage = imagecreatetruecolor($newWidth, $newHeight);

        // 保留PNG透明度
        if ($mimeType === 'image/png') {
            imagealphablending($dstImage, false);
            imagesavealpha($dstImage, true);
            $transparent = imagecolorallocatealpha($dstImage, 255, 255, 255, 127);
            imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $transparent);
        }

        // 缩放图像
        imagecopyresampled(
            $dstImage, $srcImage,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $origWidth, $origHeight
        );

        // 输出到内存
        ob_start();
        imagejpeg($dstImage, null, $quality);
        $compressedData = ob_get_clean();

        // 释放资源
        imagedestroy($srcImage);
        imagedestroy($dstImage);

        if ($compressedData === false) {
            $this->log("错误: 压缩失败");
            return null;
        }

        $this->log("压缩后Base64长度: " . strlen(base64_encode($compressedData)) . " 字符");

        return [
            'base64' => base64_encode($compressedData),
            'width' => $newWidth,
            'height' => $newHeight
        ];
    }

    /**
     * 调用Dataler API
     *
     * @param array $requestData 请求数据
     * @return array|null 响应数据或null
     */
    private function callAPI(array $requestData): ?array
    {
        $url = $this->baseUrl . $this->model . ':generateContent';

        $ch = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $this->log("发送API请求到: {$url}");

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log("CURL错误: {$error}");
            return null;
        }

        if ($httpCode !== 200) {
            $this->log("HTTP错误: {$httpCode}");
            $this->log("响应: " . substr($response, 0, 500));
            return null;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON解析错误: " . json_last_error_msg());
            return null;
        }

        return $data;
    }

    /**
     * 步骤1: 生成MASK蒙板
     *
     * @param string $sceneImageBase64 场景图Base64
     * @param string|null $targetDesc 用户描述的要替换的目标（可选）
     * @return string|null MASK图片Base64
     */
    public function generateMask(string $sceneImageBase64, ?string $targetDesc = null): ?string
    {
        $this->log("========================================");
        $this->log("【步骤2/4】AI生成目标区域MASK...");
        $this->log("========================================");

        // 构建MASK生成Prompt
        if ($targetDesc && strlen($targetDesc) > 0) {
            $this->log("将按用户描述定位目标: {$targetDesc}");
            $maskPrompt = "Generate an image: 请仔细观察这张图片，为 inpainting 创建一张精确的黑白蒙版(MASK)图。\n\n" .
                "【要做成白色蒙版的目标区域】\n" .
                $targetDesc . "\n\n" .
                "请把上述描述的所有内容（包括它们占据的完整区域）都涂成纯白色(#FFFFFF)。\n" .
                "图片中其他所有内容（背景、墙壁、地板、人物、文字、其他不相关的物品）都涂成纯黑色(#000000)。\n\n" .
                "Rules / 规则:\n" .
                "- The MASK must be the EXACT SAME dimensions as the original image\n" .
                "- WHITE (#FFFFFF) = the target area described above (to be replaced)\n" .
                "- BLACK (#000000) = everything else (to be kept)\n" .
                "- Cover the ENTIRE target area including all parts mentioned in the description\n" .
                "- Use smooth edges with a small margin (3-5 pixels) around the target\n" .
                "- Clean black and white only, NO gray, NO gradients\n" .
                "- Do NOT include shadows or reflections in the white area\n" .
                "Output ONLY the mask image, no text.";
        } else {
            $this->log("将自动识别图片主体产品");
            $maskPrompt = "Generate an image: Look at this image carefully. Create a precise MASK image for inpainting. " .
                "The MASK must be the EXACT SAME dimensions as the original image. " .
                "Identify the MAIN PRODUCT/SUBJECT in the image and mask it.\n" .
                "Rules:\n" .
                "- Paint the MAIN PRODUCT/SUBJECT area in PURE WHITE (#FFFFFF)\n" .
                "- Paint EVERYTHING ELSE in PURE BLACK (#000000)\n" .
                "- Cover the product outline with a small margin (3-5 pixels)\n" .
                "- Use smooth edges, no jagged borders\n" .
                "- Clean black and white only, NO gray, NO gradients\n" .
                "- Do NOT include shadows in the white area\n" .
                "Output ONLY the mask image, no text.";
        }

        $requestData = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $maskPrompt],
                        ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $sceneImageBase64]]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE']
            ]
        ];

        $this->log("发送MASK生成请求...");
        $response = $this->callAPI($requestData);

        if (!$response) {
            $this->log("错误: MASK生成API调用失败");
            return null;
        }

        // 提取MASK图片
        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['inlineData']['data'])) {
                    $this->log("MASK生成成功！");
                    return $part['inlineData']['data'];
                }
            }
        }

        $this->log("错误: 未能从响应中提取MASK图片");
        if (isset($response['candidates'][0]['finishReason'])) {
            $this->log("完成原因: " . $response['candidates'][0]['finishReason']);
        }

        return null;
    }

    /**
     * 步骤2: 反推产品外观特征
     *
     * @param string $productImageBase64 产品图Base64
     * @return string 产品描述
     */
    public function analyzeProduct(string $productImageBase64): string
    {
        $this->log("========================================");
        $this->log("【步骤3/4】反推产品外观特征...");
        $this->log("========================================");

        $productDescPrompt = "请极其详细地描述这张图中产品的视觉外观特征，用于在另一张图中精确还原这个产品。\n\n" .
            "必须描述：\n" .
            "1. 整体形状和轮廓：精确的外形、弧度、棱角\n" .
            "2. 尺寸比例：各部分之间的比例关系（如宽高比）\n" .
            "3. 颜色：每个部位的精确颜色（用具体色名，如'深空灰''象牙白''玫瑰金'）\n" .
            "4. 材质质感：金属/塑料/木材/玻璃/布料等，哑光/亮面/磨砂\n" .
            "5. 表面细节：纹理、图案、反光特性、logo位置和样式\n" .
            "6. 结构特征：按钮、接口、把手、铰链、缝线等所有可见部件\n" .
            "7. 产品数量和排列：单个还是多个，如何摆放\n\n" .
            "请用英文输出，格式为简洁的描述段落，不要编号，直接描述外观特征。";

        $requestData = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $productDescPrompt],
                        ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $productImageBase64]]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['TEXT'],
                'temperature' => 0.2,
                'maxOutputTokens' => 1024
            ]
        ];

        $this->log("发送产品特征分析请求...");
        $response = $this->callAPI($requestData);

        $description = '';
        if ($response && isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    $description = $part['text'];
                    break;
                }
            }
        }

        if (strlen($description) > 0) {
            $this->log("产品特征分析成功！描述长度: " . strlen($description) . " 字符");
            $this->log("预览: " . substr($description, 0, 150) . "...");
        } else {
            $this->log("产品特征分析未返回结果，将仅依靠图片进行替换");
        }

        return $description;
    }

    /**
     * 步骤3: 执行Inpainting精确替换
     *
     * @param string $sceneImageBase64 场景图Base64
     * @param string $maskBase64 MASK蒙板Base64
     * @param string $productImageBase64 新产品图Base64
     * @param string $productDescription 产品描述
     * @param string|null $targetDesc 用户指定的替换目标描述
     * @return string|null 生成图片的Base64
     */
    public function inpaint(
        string $sceneImageBase64,
        string $maskBase64,
        string $productImageBase64,
        string $productDescription,
        ?string $targetDesc = null
    ): ?string {
        $this->log("========================================");
        $this->log("【步骤4/4】执行精确替换（Inpainting）...");
        $this->log("========================================");

        // 构建Inpainting指令
        $inpaintPrompt = "Generate an image: I am providing three images:\n" .
            "1. The FIRST image is the original photo (the scene/background to keep)\n" .
            "2. The SECOND image is a black-and-white MASK where WHITE areas indicate the region to replace\n" .
            "3. The THIRD image is the new product/object that should be placed into the white masked area\n\n";

        // 加入反推的产品外观描述
        if (strlen($productDescription) > 0) {
            $inpaintPrompt .= "**[PRODUCT APPEARANCE REFERENCE - from the THIRD image]**\n" .
                $productDescription . "\n\n";
        }

        // 如果用户描述了替换目标，加入上下文说明
        if ($targetDesc && strlen($targetDesc) > 0) {
            $inpaintPrompt .= "Context: The white masked area in the original image corresponds to: [{$targetDesc}]. " .
                "Replace this entire area with the product from the third image.\n\n";
        }

        $inpaintPrompt .= "**[CRITICAL - PRODUCT FIDELITY RULES]**\n" .
            "The product from the THIRD image must be reproduced with 100% visual fidelity:\n" .
            "- EXACT original shape, proportions, and aspect ratio — NO stretching, squishing, warping, or distortion\n" .
            "- EXACT original colors, materials, textures, surface details, logos, and text as described above\n" .
            "- EXACT original structural features (buttons, handles, edges, curves, patterns)\n" .
            "- Scale the product uniformly to fit the masked area — maintain width-to-height ratio strictly\n" .
            "- If the masked area is a different shape than the product, fit the product within the area with appropriate background fill — do NOT deform the product to fill the mask\n" .
            "- The product in the result must look like an exact copy of the THIRD image, just placed into a new scene\n\n" .
            "Placement rules:\n" .
            "- Adjust ONLY the viewing angle slightly to match the scene perspective\n" .
            "- Match the scene lighting direction and color temperature on the product surface\n" .
            "- Add natural shadows consistent with the scene light source\n" .
            "- Blend edges seamlessly with the surrounding area\n" .
            "- Keep ALL black masked areas (background, people, environment) EXACTLY unchanged\n" .
            "- Preserve the exact resolution and aspect ratio of the original image";

        $requestData = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $inpaintPrompt],
                        ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $sceneImageBase64]],
                        ['inlineData' => ['mimeType' => 'image/png', 'data' => $maskBase64]],
                        ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $productImageBase64]]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE']
            ]
        ];

        $this->log("发送Inpainting请求（原图 + MASK + 产品图 + 产品描述）...");
        $response = $this->callAPI($requestData);

        if (!$response) {
            $this->log("错误: Inpainting API调用失败");
            return null;
        }

        // 提取生成的图片
        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['inlineData']['data'])) {
                    $this->log("========================================");
                    $this->log("【精确P图成功！】");
                    $this->log("========================================");
                    return $part['inlineData']['data'];
                }
            }
        }

        $this->log("错误: 未能在响应中找到生成的图片");
        if (isset($response['candidates'][0]['finishReason'])) {
            $this->log("完成原因: " . $response['candidates'][0]['finishReason']);
        }

        return null;
    }

    /**
     * 完整的蒙板替换流程
     *
     * @param string $sceneImagePath 场景图路径（含要替换的产品）
     * @param string $productImagePath 新产品图路径
     * @param string|null $targetDesc 用户描述的要替换的目标（如"红色手提包"）
     * @param string $outputPath 输出图片路径
     * @param bool $compress 是否压缩图片
     * @return bool 是否成功
     */
    public function replaceProductWithMask(
        string $sceneImagePath,
        string $productImagePath,
        ?string $targetDesc = null,
        string $outputPath = 'output.png',
        bool $compress = true
    ): bool {
        $this->log("========================================");
        $this->log("【精确P图 - MASK模式】开始执行");
        $this->log("========================================");
        $this->log("垫图(场景): {$sceneImagePath}");
        $this->log("新产品图: {$productImagePath}");
        if ($targetDesc) {
            $this->log("替换目标: {$targetDesc}");
        } else {
            $this->log("替换目标: 自动识别主体");
        }

        // ========== 步骤1: 读取并准备图片 ==========
        $this->log("========================================");
        $this->log("【步骤1/4】读取并准备图片...");
        $this->log("========================================");

        if ($compress) {
            $sceneData = $this->compressImage($sceneImagePath, 1500);
            $productData = $this->compressImage($productImagePath, 1500);
        } else {
            $sceneBase64 = $this->imageToBase64($sceneImagePath);
            $productBase64 = $this->imageToBase64($productImagePath);
            $sceneData = $sceneBase64 ? ['base64' => $sceneBase64, 'width' => 0, 'height' => 0] : null;
            $productData = $productBase64 ? ['base64' => $productBase64, 'width' => 0, 'height' => 0] : null;
        }

        if (!$sceneData || !$productData) {
            $this->log("错误: 图片读取失败");
            return false;
        }

        $sceneBase64 = $sceneData['base64'];
        $productBase64 = $productData['base64'];

        $this->log("图片准备完成");

        // ========== 步骤2: 生成MASK蒙板 ==========
        $maskBase64 = $this->generateMask($sceneBase64, $targetDesc);
        if (!$maskBase64) {
            $this->log("错误: MASK生成失败");
            return false;
        }

        // 保存MASK调试用（可选）
        $maskDebugPath = sys_get_temp_dir() . '/mask_' . time() . '.png';
        file_put_contents($maskDebugPath, base64_decode($maskBase64));
        $this->log("MASK调试文件: {$maskDebugPath}");

        // ========== 步骤3: 反推产品外观特征 ==========
        $productDescription = $this->analyzeProduct($productBase64);

        // ========== 步骤4: 执行Inpainting ==========
        $resultBase64 = $this->inpaint(
            $sceneBase64,
            $maskBase64,
            $productBase64,
            $productDescription,
            $targetDesc
        );

        if (!$resultBase64) {
            $this->log("错误: Inpainting生成失败");
            return false;
        }

        // 保存结果
        $resultBinary = base64_decode($resultBase64);
        if (file_put_contents($outputPath, $resultBinary) === false) {
            $this->log("错误: 无法保存结果图片");
            return false;
        }

        $this->log("结果图片保存至: {$outputPath}");
        $this->log("精确P图流程结束");

        return true;
    }
}

// ==================== 使用示例 ====================

/**
 * 示例1: 基本用法 - 自动识别并替换主体产品
 */
function example1_basic()
{
    echo "=== 示例1: 自动识别替换主体产品 ===\n";

    $apiKey = 'your-api-key-here'; // 替换为您的API Key
    $api = new DatalerInpaintingAPI($apiKey);

    $result = $api->replaceProductWithMask(
        'scene.jpg',        // 场景图：模特手持旧产品
        'new_product.jpg',  // 新产品图：要替换进去的产品
        null,               // 不指定目标，自动识别
        'output_auto.png',  // 输出路径
        true                // 启用压缩
    );

    echo $result ? "成功！\n" : "失败！\n";
}

/**
 * 示例2: 高级用法 - 指定替换目标
 */
function example2_targeted()
{
    echo "=== 示例2: 指定替换目标 ===\n";

    $apiKey = 'your-api-key-here';
    $api = new DatalerInpaintingAPI($apiKey);

    $result = $api->replaceProductWithMask(
        'model_with_bag.jpg',   // 场景图：模特拿着红色手提包
        'blue_bag.jpg',         // 新产品图：蓝色手提包
        '红色手提包',            // 明确指定要替换的是红色手提包
        'output_blue_bag.png',  // 输出路径
        true
    );

    echo $result ? "成功！\n" : "失败！\n";
}

/**
 * 示例3: 分步调用（更灵活的控制）
 */
function example3_step_by_step()
{
    echo "=== 示例3: 分步调用 ===\n";

    $apiKey = 'your-api-key-here';
    $api = new DatalerInpaintingAPI($apiKey);

    // 1. 准备图片
    $sceneData = $api->compressImage('scene.jpg', 1500);
    $productData = $api->compressImage('product.jpg', 1500);

    if (!$sceneData || !$productData) {
        echo "图片准备失败\n";
        return;
    }

    // 2. 生成MASK
    $maskBase64 = $api->generateMask($sceneData['base64'], '桌子上的笔记本电脑');
    if (!$maskBase64) {
        echo "MASK生成失败\n";
        return;
    }

    // 3. 分析产品
    $productDesc = $api->analyzeProduct($productData['base64']);

    // 4. 执行替换
    $resultBase64 = $api->inpaint(
        $sceneData['base64'],
        $maskBase64,
        $productData['base64'],
        $productDesc,
        '桌子上的笔记本电脑'
    );

    if ($resultBase64) {
        file_put_contents('output_step.png', base64_decode($resultBase64));
        echo "成功！结果保存至 output_step.png\n";
    } else {
        echo "替换失败\n";
    }
}

/**
 * 示例4: 批量处理
 */
function example4_batch()
{
    echo "=== 示例4: 批量处理 ===\n";

    $apiKey = 'your-api-key-here';
    $api = new DatalerInpaintingAPI($apiKey);

    $scenes = [
        'scene1.jpg',
        'scene2.jpg',
        'scene3.jpg'
    ];

    $newProduct = 'new_product.jpg';
    $target = '画面中央的手机';

    foreach ($scenes as $index => $scene) {
        echo "处理第 " . ($index + 1) . "/" . count($scenes) . " 张...\n";

        $output = "output_batch_{$index}.png";
        $success = $api->replaceProductWithMask(
            $scene,
            $newProduct,
            $target,
            $output,
            true
        );

        echo $success ? "✓ {$output}\n" : "✗ 失败\n";

        // 添加延迟避免API限流
        if ($index < count($scenes) - 1) {
            sleep(2);
        }
    }
}

/**
 * 示例5: 带自定义日志回调
 */
function example5_custom_logging()
{
    echo "=== 示例5: 自定义日志回调 ===\n";

    $apiKey = 'your-api-key-here';
    $api = new DatalerInpaintingAPI($apiKey);

    // 自定义日志处理：写入文件
    $logFile = fopen('inpainting.log', 'a');
    $api->logCallback = function($message) use ($logFile) {
        fwrite($logFile, $message);
        echo $message; // 同时输出到屏幕
    };

    $result = $api->replaceProductWithMask(
        'scene.jpg',
        'product.jpg',
        null,
        'output.png',
        true
    );

    fclose($logFile);
    echo $result ? "成功！\n" : "失败！\n";
}

// ==================== 命令行运行 ====================

if (PHP_SAPI === 'cli') {
    echo "AI图像生成API - 精确P图示例\n";
    echo "========================================\n";
    echo "\n可用示例:\n";
    echo "1 - 基本用法（自动识别）\n";
    echo "2 - 指定替换目标\n";
    echo "3 - 分步调用\n";
    echo "4 - 批量处理\n";
    echo "5 - 自定义日志\n";
    echo "\n使用方法: php {$argv[0]} [示例编号]\n";

    if (isset($argv[1])) {
        switch ($argv[1]) {
            case '1': example1_basic(); break;
            case '2': example2_targeted(); break;
            case '3': example3_step_by_step(); break;
            case '4': example4_batch(); break;
            case '5': example5_custom_logging(); break;
            default: echo "无效的示例编号\n";
        }
    }
}
