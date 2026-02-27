<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PageController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_chat');
        }

        return $this->render('auth/home.html.twig');
    }

    #[Route('/register', name: 'app_register_page', methods: ['GET'])]
    public function registerPage(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_chat');
        }

        return $this->render('auth/register.html.twig', [
            'token' => (string) $request->query->get('token', ''),
        ]);
    }

    #[Route('/reset-password', name: 'app_reset_password_page', methods: ['GET'])]
    public function resetPasswordPage(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_chat');
        }

        return $this->render('auth/reset_password.html.twig', [
            'token' => (string) $request->query->get('token', ''),
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/chat', name: 'app_chat', methods: ['GET'])]
    public function chat(): Response
    {
        return $this->render('chat/index.html.twig');
    }
}
