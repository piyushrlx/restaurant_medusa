from PIL import Image

img_path = 'assets/images/delivery_scooter.png'
img = Image.open(img_path)
width, height = img.size
print(f"Dimensions: {width}x{height}")

# Inspect first few rows of pixels
pixels = []
for y in range(20):
    row = []
    for x in range(20):
        row.append(img.getpixel((x, y)))
    pixels.append(row)

# Print some unique colors in the corner 50x50 region
unique_colors = set()
for y in range(50):
    for x in range(50):
        unique_colors.add(img.getpixel((x, y)))

print("Number of unique colors in corner 50x50:", len(unique_colors))
print("Sample colors:", list(unique_colors)[:20])
