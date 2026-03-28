# Dataler.com AI图像生成API使用指南 - 精确P图与蒙板替换技术

## 一、平台简介

**Dataler.com** 是一家专业的第三方AI中转API平台，提供以下核心优势：

- **官方2.2折价格**：大幅降低AI图像生成成本
- **全模型支持**：对接几乎所有主流AI图像生成模型
- **动态负载自适应**：智能调度确保稳定高效的API响应
- **兼容Gemini API格式**：无缝迁移现有项目

API端点：`https://dataler.com/v1beta/models/{model}:generateContent`

---

## 二、核心功能详解

### 2.1 AI反推Prompt功能

通过分析图片自动生成详细的AI图像生成提示词，包含：
- 主体分析（人物/物体/场景）
- 构图与视角
- 色彩方案
- 光影效果
- 艺术风格
- 材质纹理
- 背景环境
- 相机/镜头特效

### 2.2 垫图换产品生图（Prompt替换模式）

**流程概述**：
1. **分析垫图产品**：AI详细分析产品图片，提取外观、材质、颜色等特征
2. **Prompt整合**：将产品描述与用户提供的场景Prompt智能融合
3. **生成新图**：使用新Prompt结合垫图生成最终图像

**适用场景**：
- 电商产品图替换
- 保持场景氛围更换产品
- 批量生成相似风格的产品展示图

### 2.3 原图产品换垫图产品（双图融合模式）

**流程概述**：
1. **反推场景图**：提取完整场景、人物外貌、情绪、着装、光线等信息
2. **反推产品图**：详细分析产品外观、尺寸、材质、颜色、结构特征
3. **智能整合**：将产品描述融入场景描述，保持人物和场景不变
4. **双图垫图生成**：使用新Prompt + 两张原图作为垫图生成结果

**核心优势**：
- 人物外貌100%保持一致
- 人物情绪状态完全保留
- 场景光线氛围不变
- 仅替换产品外观

### 2.4 精确P图 - MASK蒙板替换模式（button2核心逻辑）

这是最精确的图像替换技术，适用于需要像素级控制的产品替换场景。

#### 工作流程

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   垫图(场景图)   │     │   产品图(新)    │     │   用户描述(可选) │
└────────┬────────┘     └────────┬────────┘     └────────┬────────┘
         │                       │                       │
         ▼                       │                       │
┌─────────────────┐              │                       │
│  AI生成MASK蒙板 │◄─────────────┴───────────────────────┘
│ 白色=替换区域   │    (根据用户描述或自动识别主体)
│ 黑色=保留区域   │
└────────┬────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────┐
│                    Inpainting生成阶段                        │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐         │
│  │  原图场景   │  │  MASK蒙板   │  │  新产品图   │         │
│  │  (保留)     │  │  (黑白)     │  │  (替换来源) │         │
│  └─────────────┘  └─────────────┘  └─────────────┘         │
│         │              │              │                     │
│         └──────────────┼──────────────┘                     │
│                        ▼                                    │
│              ┌─────────────────┐                           │
│              │   AI Inpainting │                           │
│              │   精确替换生成   │                           │
│              └─────────────────┘                           │
└─────────────────────────────────────────────────────────────┘
```

#### 详细步骤

**步骤1：图片准备**
- 读取垫图（场景图）和新产品图
- 智能压缩（最大边1500px，保持质量同时减少传输）
- 转换为Base64格式

**步骤2：AI生成MASK蒙板**

根据用户是否提供目标描述，采用不同策略：

**策略A - 用户指定替换目标**：
```
Prompt示例：
"Generate an image: 请仔细观察这张图片，为 inpainting 创建一张精确的黑白蒙版(MASK)图。

【要做成白色蒙版的目标区域】
[用户描述的目标物品]

请把上述描述的所有内容（包括它们占据的完整区域）都涂成纯白色(#FFFFFF)。
图片中其他所有内容（背景、墙壁、地板、人物、文字、其他不相关的物品）都涂成纯黑色(#000000)。

Rules:
- The MASK must be the EXACT SAME dimensions as the original image
- WHITE (#FFFFFF) = the target area described above (to be replaced)
- BLACK (#000000) = everything else (to be kept)
- Cover the ENTIRE target area including all parts mentioned in the description
- Use smooth edges with a small margin (3-5 pixels) around the target
- Clean black and white only, NO gray, NO gradients
- Do NOT include shadows or reflections in the white area
- Output ONLY the mask image, no text."
```

**策略B - 自动识别主体**：
```
Prompt示例：
"Generate an image: Look at this image carefully. Create a precise MASK image for inpainting.
The MASK must be the EXACT SAME dimensions as the original image.
Identify the MAIN PRODUCT/SUBJECT in the image and mask it.

Rules:
- Paint the MAIN PRODUCT/SUBJECT area in PURE WHITE (#FFFFFF)
- Paint EVERYTHING ELSE in PURE BLACK (#000000)
- Cover the product outline with a small margin (3-5 pixels)
- Use smooth edges, no jagged borders
- Clean black and white only, NO gray, NO gradients
- Do NOT include shadows in the white area
- Output ONLY the mask image, no text."
```

**步骤3：反推产品外观特征**

AI分析新产品图，提取：
- 整体形状和轮廓
- 尺寸比例
- 精确颜色（深空灰、象牙白、玫瑰金等）
- 材质质感（金属/塑料/木材/玻璃/布料，哑光/亮面/磨砂）
- 表面细节（纹理、图案、反光特性、logo位置）
- 结构特征（按钮、接口、把手、铰链、缝线）
- 产品数量和排列

**步骤4：Inpainting精确替换**

构建包含四部分的请求：
1. **文本指令**：详细的替换规则说明
2. **原图场景**：作为背景保留
3. **MASK蒙板**：黑白图，白色区域将被替换
4. **新产品图**：替换来源

**关键指令模板**：
```
"Generate an image: I am providing three images:
1. The FIRST image is the original photo (the scene/background to keep)
2. The SECOND image is a black-and-white MASK where WHITE areas indicate the region to replace
3. The THIRD image is the new product/object that should be placed into the white masked area

**[PRODUCT APPEARANCE REFERENCE - from the THIRD image]**
[反推的产品外观描述]

**[CRITICAL - PRODUCT FIDELITY RULES]**
The product from the THIRD image must be reproduced with 100% visual fidelity:
- EXACT original shape, proportions, and aspect ratio — NO stretching, squishing, warping, or distortion
- EXACT original colors, materials, textures, surface details, logos, and text
- EXACT original structural features (buttons, handles, edges, curves, patterns)
- Scale the product uniformly to fit the masked area — maintain width-to-height ratio strictly
- If the masked area is a different shape than the product, fit the product within the area with appropriate background fill — do NOT deform the product to fill the mask
- The product in the result must look like an exact copy of the THIRD image, just placed into a new scene

Placement rules:
- Adjust ONLY the viewing angle slightly to match the scene perspective
- Match the scene lighting direction and color temperature on the product surface
- Add natural shadows consistent with the scene light source
- Blend edges seamlessly with the surrounding area
- Keep ALL black masked areas (background, people, environment) EXACTLY unchanged
- Preserve the exact resolution and aspect ratio of the original image"
```

---

## 三、技术要点总结

### 3.1 蒙板生成要点
- **纯黑白**：不允许灰色或渐变
- **边缘平滑**：3-5像素的过渡边距
- **不包含阴影**：白色区域仅包含产品本身
- **尺寸一致**：MASK必须与原图尺寸完全相同

### 3.2 产品保真要点
- **形状不变**：禁止拉伸、压缩、变形
- **比例保持**：宽高比严格保持
- **材质还原**：颜色、纹理、反光特性100%还原
- **结构完整**：所有可见部件必须保留

### 3.3 场景融合要点
- **透视匹配**：根据场景调整产品视角
- **光影一致**：匹配场景光源方向和色温
- **阴影自然**：添加符合光源的阴影
- **边缘融合**：与周围环境无缝衔接

---

## 四、应用场景

1. **电商产品替换**：模特手持产品图，快速替换不同款式
2. **场景营销图**：保持精美场景，更换展示产品
3. **广告素材制作**：批量生成同一产品的不同场景展示
4. **产品迭代展示**：同一角度展示产品不同配色/配置
5. **虚拟试穿/试用**：将产品自然融入用户场景

---

## 五、最佳实践

1. **图片质量**：建议使用清晰、光线均匀的产品图
2. **描述精确**：用户提供的目标描述越详细，MASK定位越准确
3. **多试几次**：AI生成有一定随机性，不满意可多次尝试
4. **尺寸匹配**：场景图和产品图分辨率建议相近
5. **压缩策略**：大图片适当压缩可提升API响应速度

---

## 六、API请求格式

### 基础请求结构

```json
{
  "contents": [
    {
      "role": "user",
      "parts": [
        {"text": "提示词内容"},
        {"inlineData": {"mimeType": "image/jpeg", "data": "base64编码的图片"}}
      ]
    }
  ],
  "generationConfig": {
    "responseModalities": ["TEXT", "IMAGE"],
    "temperature": 0.3,
    "maxOutputTokens": 2048,
    "imageConfig": {
      "aspectRatio": "1:1",
      "imageSize": "1K"
    }
  }
}
```

### 支持的模型

- `gemini-3-pro-image-preview`：专业图像生成模型
- `gemini-3.1-flash-image-preview`：快速图像生成模型

---

*本文档基于Dataler.com API和Gemini图像生成技术编写*
