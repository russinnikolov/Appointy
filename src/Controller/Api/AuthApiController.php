<?php

namespace App\Controller\Api;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Util\PhoneValidator;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Auth')]
#[Route('/api/v1/auth')]
class AuthApiController extends AbstractController
{
    #[OA\Post(
        summary: 'Register a new client or business account',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name',             type: 'string',  example: 'Ivan Ivanov'),
            new OA\Property(property: 'email',            type: 'string',  example: 'ivan@example.com'),
            new OA\Property(property: 'password',         type: 'string',  example: 'secret123'),
            new OA\Property(property: 'type',             type: 'string',  enum: ['client', 'business'], example: 'client'),
            new OA\Property(property: 'phone',            type: 'string',  example: '+359888123456'),
            new OA\Property(property: 'messaging_apps',   type: 'array',   items: new OA\Items(type: 'string', enum: ['whatsapp','telegram','viber','sms'])),
            new OA\Property(property: 'org_name',         type: 'string',  example: 'Best Barbershop'),
            new OA\Property(property: 'org_phone',        type: 'string',  example: '+359888000000'),
            new OA\Property(property: 'org_email',        type: 'string',  example: 'info@bestbarber.bg'),
            new OA\Property(property: 'org_category',     type: 'string',  example: 'Barbershop'),
            new OA\Property(property: 'org_address',      type: 'string',  example: 'ul. Vitosha 1'),
            new OA\Property(property: 'org_city',         type: 'string',  example: 'Sofia'),
            new OA\Property(property: 'org_country',      type: 'string',  example: 'Bulgaria'),
            new OA\Property(property: 'org_zip',          type: 'string',  example: '1000'),
            new OA\Property(property: 'org_description',  type: 'string',  example: 'The best cuts in town'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Registered — returns JWT token and user object'),
            new OA\Response(response: 409, description: 'Email already registered'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        UserRepository $userRepo,
        JWTTokenManagerInterface $jwt
    ): JsonResponse {
        $body = json_decode($request->getContent(), true) ?? [];

        $name  = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $pass  = $body['password'] ?? '';
        $type  = $body['type'] ?? User::TYPE_CLIENT;
        $phone = trim($body['phone'] ?? '');

        if (!$name || !$email || !$pass) {
            return $this->error('Name, email and password are required.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Invalid email address.', 422);
        }
        if ($phone && !PhoneValidator::isValid($phone)) {
            return $this->error('Invalid phone number.', 422);
        }
        if (strlen($pass) < 8) {
            return $this->error('Password must be at least 8 characters.', 422);
        }
        if ($userRepo->findOneBy(['email' => $email])) {
            return $this->error('Email already registered.', 409);
        }
        if (!in_array($type, [User::TYPE_CLIENT, User::TYPE_BUSINESS], true)) {
            return $this->error('Type must be "client" or "business".', 422);
        }

        $apps = array_values(array_intersect($body['messaging_apps'] ?? [], User::MESSAGING_APPS));

        $user = new User();
        $user->setName($name)
             ->setEmail($email)
             ->setPhone($phone ?: null)
             ->setType($type)
             ->setMessagingApps($apps)
             ->setPassword($hasher->hashPassword($user, $pass));

        if ($type === User::TYPE_BUSINESS) {
            $orgName  = trim($body['org_name'] ?? '');
            $orgPhone = trim($body['org_phone'] ?? '');
            if (!$orgName) {
                return $this->error('Organization name is required for business accounts.', 422);
            }
            if ($orgPhone && !PhoneValidator::isValid($orgPhone)) {
                return $this->error('Invalid organization phone number.', 422);
            }

            $org = new Organization();
            $org->setName($orgName)
                ->setEmail(trim($body['org_email'] ?? '') ?: null)
                ->setPhone($orgPhone ?: null)
                ->setCategory(trim($body['org_category'] ?? '') ?: null)
                ->setAddress(trim($body['org_address'] ?? '') ?: null)
                ->setCity(trim($body['org_city'] ?? '') ?: null)
                ->setCountry(trim($body['org_country'] ?? '') ?: null)
                ->setZipCode(trim($body['org_zip'] ?? '') ?: null)
                ->setDescription(trim($body['org_description'] ?? '') ?: null);

            $em->persist($org);
            $user->setOrganization($org);
        }

        $em->persist($user);
        $em->flush();

        return new JsonResponse([
            'token' => $jwt->create($user),
            'user'  => $this->serializeUser($user),
        ], 201);
    }

    #[OA\Post(
        summary: 'Login and receive a JWT token',
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email',    type: 'string', example: 'ivan@example.com'),
                new OA\Property(property: 'password', type: 'string', example: 'secret123'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Returns JWT token and user object'),
            new OA\Response(response: 401, description: 'Invalid credentials'),
            new OA\Response(response: 422, description: 'Missing fields'),
        ]
    )]
    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(
        Request $request,
        UserRepository $userRepo,
        UserPasswordHasherInterface $hasher,
        JWTTokenManagerInterface $jwt
    ): JsonResponse {
        $body  = json_decode($request->getContent(), true) ?? [];
        $email = trim($body['email'] ?? '');
        $pass  = $body['password'] ?? '';

        if (!$email || !$pass) {
            return $this->error('Email and password are required.', 422);
        }

        $user = $userRepo->findOneBy(['email' => $email]);
        if (!$user || !$hasher->isPasswordValid($user, $pass)) {
            return $this->error('Invalid credentials.', 401);
        }

        return new JsonResponse([
            'token' => $jwt->create($user),
            'user'  => $this->serializeUser($user),
        ]);
    }

    #[OA\Get(
        summary: 'Get the currently authenticated user',
        responses: [
            new OA\Response(response: 200, description: 'Current user object'),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->error('Not authenticated.', 401);
        }

        return new JsonResponse(['user' => $this->serializeUser($user)]);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id'              => $user->getId(),
            'name'            => $user->getName(),
            'email'           => $user->getEmail(),
            'phone'           => $user->getPhone(),
            'type'            => $user->getType(),
            'messaging_apps'  => $user->getMessagingApps(),
            'organization_id' => $user->getOrganization()?->getId(),
        ];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
